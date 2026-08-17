<?php

namespace App\Controller;

use App\Entity\TrustedDevice;
use App\Repository\AccountRepository;
use App\Repository\TrustedDeviceRepository;
use App\Security\DeviceTrust\DeviceTrustTicket;
use App\Security\DeviceTrust\ReturnToValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two legs of the cross-app "trusted device" handshake (see
 * /opt/vitrine/CLAUDE.md and /opt/equi/CLAUDE.md for the full picture):
 * - /trust-device/confirm: called via browser redirect right after an app
 *   verified 2FA and the user ticked "trust this device". Sets Yzendra ID's
 *   own long-lived cookie, scoped to this domain only.
 * - /check-device: called via browser redirect by an app *before* showing
 *   its own 2FA form. Reads that cookie (if present) and bounces back with
 *   a short-lived signed ticket the app can verify with the shared RSA
 *   public key it already has for JWT verification.
 *
 * Both are plain GET redirects with no auth of their own - trust comes
 * entirely from the signed tickets exchanged, not from a session on the
 * calling app's side.
 */
class DeviceTrustController extends AbstractController
{
    private const COOKIE_NAME = 'yzendra_trusted_device';
    private const TRUST_LIFETIME = 90 * 24 * 3600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrustedDeviceRepository $trustedDeviceRepository,
        private readonly AccountRepository $accountRepository,
        #[Autowire('%env(resolve:JWT_SECRET_KEY)%')] private readonly string $privateKeyPath,
        #[Autowire('%env(resolve:JWT_PUBLIC_KEY)%')] private readonly string $publicKeyPath,
        #[Autowire('%env(JWT_PASSPHRASE)%')] private readonly string $passphrase,
    ) {
    }

    #[Route('/trust-device/confirm', name: 'device_trust_confirm', methods: ['GET'])]
    public function confirm(Request $request): Response
    {
        $returnTo = (string) $request->query->get('return_to', '');
        if (!ReturnToValidator::isAllowed($returnTo)) {
            throw $this->createNotFoundException();
        }

        $payload = DeviceTrustTicket::verify(
            (string) $request->query->get('ticket', ''),
            file_get_contents($this->publicKeyPath)
        );

        if (null === $payload || 'register_device' !== ($payload['typ'] ?? null)) {
            // Ticket invalide/expiré : on ne bloque pas le login pour autant,
            // on repart simplement sans poser de cookie de confiance.
            return new RedirectResponse($returnTo);
        }

        $account = $this->accountRepository->find($payload['account_id'] ?? 0);
        if (null === $account) {
            return new RedirectResponse($returnTo);
        }

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        $device = new TrustedDevice();
        $device->setAccount($account);
        $device->setSelector($selector);
        $device->setValidatorHash(hash('sha256', $validator));
        $device->setCreatedAt(new \DateTimeImmutable());
        $device->setExpiresAt(new \DateTimeImmutable('+90 days'));

        $this->entityManager->persist($device);
        $this->entityManager->flush();

        $response = new RedirectResponse($returnTo);
        $response->headers->setCookie(Cookie::create(
            self::COOKIE_NAME,
            $selector.'.'.$validator,
            time() + self::TRUST_LIFETIME,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    #[Route('/check-device', name: 'device_check', methods: ['GET'])]
    public function check(Request $request): Response
    {
        $returnTo = (string) $request->query->get('return_to', '');
        if (!ReturnToValidator::isAllowed($returnTo)) {
            throw $this->createNotFoundException();
        }

        $accountId = (int) $request->query->get('account_id', 0);
        $trusted = false;

        $cookie = $request->cookies->get(self::COOKIE_NAME);
        if (null !== $cookie && str_contains($cookie, '.')) {
            [$selector, $validator] = explode('.', $cookie, 2);
            $device = $this->trustedDeviceRepository->findValidBySelector($selector);

            if (null !== $device
                && hash_equals($device->getValidatorHash(), hash('sha256', $validator))
                && $device->getAccount()->getId() === $accountId
            ) {
                $trusted = true;
                // Fenêtre glissante de 90 jours, comme le trusted_device
                // local de scheb côté vitrine/Equi : tant que l'appareil
                // sert, la confiance ne périme pas.
                $device->setExpiresAt(new \DateTimeImmutable('+90 days'));
                $device->setLastUsedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        }

        $ticket = DeviceTrustTicket::sign([
            'typ' => 'device_trust',
            'account_id' => $accountId,
            'trusted' => $trusted,
            'exp' => time() + 120,
            'jti' => bin2hex(random_bytes(12)),
        ], file_get_contents($this->privateKeyPath), $this->passphrase);

        $separator = str_contains($returnTo, '?') ? '&' : '?';

        return new RedirectResponse($returnTo.$separator.'ticket='.urlencode($ticket));
    }
}
