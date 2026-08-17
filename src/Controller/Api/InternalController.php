<?php

namespace App\Controller\Api;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Routes callable only by the vitrine/Equi/futurs outils backends
 * themselves - never by a browser directly - authenticated with a shared
 * secret instead of a user Bearer token, since the whole point is to act
 * on an account that ISN'T logged in (forgot-password flow). PUBLIC_ACCESS
 * at the Symfony security layer (see security.yaml), the secret check
 * happens in the controller.
 */
#[Route('/api/internal')]
class InternalController extends AbstractApiController
{
    /**
     * Called by an app's ResetPasswordController once IT has already
     * validated its own reset token (selector/validator, proves the
     * clicked link is genuine and unused) - this endpoint trusts that
     * validation already happened and just sets the new password, no
     * re-verification of the old one (that's the whole point of "forgot
     * password": there isn't one to check).
     */
    #[Route('/reset-password', name: 'api_internal_reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        #[Autowire(env: 'INTERNAL_API_SECRET')] string $internalSecret,
    ): JsonResponse {
        if (!hash_equals($internalSecret, (string) $request->headers->get('X-Internal-Secret', ''))) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $violations = $validator->validate($data, new Assert\Collection(
            fields: [
                'email' => [new Assert\NotBlank(), new Assert\Email()],
                'newPassword' => [new Assert\NotBlank(), new Assert\Length(min: 6, max: 4096)],
            ],
            allowExtraFields: true,
        ));
        if (\count($violations) > 0) {
            return $this->violationsResponse($violations);
        }

        $account = $entityManager->getRepository(Account::class)->findOneBy(['email' => $data['email']]);
        if (null === $account) {
            return $this->json(['error' => 'Compte introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $account->setPassword($passwordHasher->hashPassword($account, $data['newPassword']));
        $entityManager->flush();

        return $this->json(['status' => 'ok']);
    }
}
