<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs an edit of a case under a row lock, with the rule that allows it read
 * again inside the lock: another tab may have moved the case on (a summons
 * sent, a petition generated) between the page load and the save.
 *
 * A refusal rolls the transaction back and reloads the case without closing
 * the entity manager, so the page can still answer with the reason. Any other
 * failure rolls back and closes the manager.
 */
final class CaseEditTransaction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @template T
     *
     * @param callable(LegalCase): bool $allowed     the rule, checked before the lock and again on the locked row
     * @param string                    $refusalCode the {@see ClaimEditRefused} reason when the rule says no
     * @param callable(): T             $work
     *
     * @return T
     *
     * @throws ClaimEditRefused
     */
    public function run(LegalCase $case, callable $allowed, string $refusalCode, callable $work): mixed
    {
        if (!$allowed($case)) {
            throw new ClaimEditRefused($refusalCode);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($case);
            if (!$allowed($case)) {
                throw new ClaimEditRefused($refusalCode);
            }

            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (ClaimEditRefused $refusal) {
            // Nothing was written before a refusal; the manager stays usable so
            // the page can say why.
            $connection->rollBack();
            $this->em->refresh($case);

            throw $refusal;
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->close();

            throw $e;
        }
    }
}
