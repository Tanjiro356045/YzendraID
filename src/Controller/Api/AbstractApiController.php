<?php

namespace App\Controller\Api;

use App\Entity\Account;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

abstract class AbstractApiController extends AbstractController
{
    protected function serializeAccount(Account $account): array
    {
        return [
            'id' => $account->getId(),
            'email' => $account->getEmail(),
            'prenom' => $account->getPrenom(),
            'nom' => $account->getNom(),
            'roles' => $account->getRoles(),
            'dateCreation' => $account->getDateCreation()?->format(\DateTimeInterface::ATOM),
        ];
    }

    protected function violationsResponse(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = $violation->getMessage();
        }

        return $this->json(['error' => 'Données invalides.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
