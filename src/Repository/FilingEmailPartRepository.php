<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingEmailPart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilingEmailPart>
 */
class FilingEmailPartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingEmailPart::class);
    }

    public function findOneByVerpToken(string $token): ?FilingEmailPart
    {
        return $this->findOneBy(['verpToken' => strtolower($token)]);
    }

    /** @param string $messageId with or without the angle brackets */
    public function findOneByMessageId(string $messageId): ?FilingEmailPart
    {
        return $this->findOneBy(['messageId' => '<' . trim($messageId, '<> ') . '>']);
    }
}
