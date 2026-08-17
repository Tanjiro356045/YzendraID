<?php

namespace App\Controller\Api;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class AuthController extends AbstractApiController
{
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        JWTTokenManagerInterface $jwtManager,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $constraints = new Assert\Collection(
            fields: [
                'email' => [new Assert\NotBlank(), new Assert\Email()],
                'prenom' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
                'nom' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
                'password' => [new Assert\NotBlank(), new Assert\Length(min: 6, max: 4096)],
            ],
            allowExtraFields: true,
        );

        $violations = $validator->validate($data, $constraints);
        if (\count($violations) > 0) {
            return $this->violationsResponse($violations);
        }

        $existing = $entityManager->getRepository(Account::class)->findOneBy(['email' => $data['email']]);
        if (null !== $existing) {
            return $this->json(['error' => 'Un compte existe déjà avec cet email.'], Response::HTTP_CONFLICT);
        }

        $account = new Account();
        $account->setEmail($data['email']);
        $account->setPrenom($data['prenom']);
        $account->setNom($data['nom']);
        $account->setDateCreation(new \DateTimeImmutable());
        $account->setPassword($passwordHasher->hashPassword($account, $data['password']));

        $entityManager->persist($account);
        $entityManager->flush();

        return $this->json([
            'token' => $jwtManager->create($account),
            'account' => $this->serializeAccount($account),
        ], Response::HTTP_CREATED);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var Account $account */
        $account = $this->getUser();

        return $this->json($this->serializeAccount($account));
    }

    /**
     * Changes the password of the currently authenticated account. Callers
     * are expected to have already re-verified the current password
     * themselves (ex. by calling POST /api/login with it) before obtaining
     * the Bearer token used here - this endpoint only checks that the token
     * is valid, not the old password again.
     */
    #[Route('/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $violations = $validator->validate($data, new Assert\Collection(
            fields: [
                'newPassword' => [new Assert\NotBlank(), new Assert\Length(min: 6, max: 4096)],
            ],
            allowExtraFields: true,
        ));
        if (\count($violations) > 0) {
            return $this->violationsResponse($violations);
        }

        /** @var Account $account */
        $account = $this->getUser();
        $account->setPassword($passwordHasher->hashPassword($account, $data['newPassword']));
        $entityManager->flush();

        return $this->json(['status' => 'ok']);
    }
}
