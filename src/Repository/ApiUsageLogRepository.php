<?php

namespace App\Repository;

use App\Entity\ApiApplication;
use App\Entity\ApiUsageLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiUsageLog>
 *
 * @method ApiUsageLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiUsageLog|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiUsageLog[]    findAll()
 * @method ApiUsageLog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiUsageLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiUsageLog::class);
    }

    public function save(ApiUsageLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array{application?: ?ApiApplication, q?: ?string, method?: ?string} $filters
     *
     * @return ApiUsageLog[]
     */
    public function findFilteredPaginated(array $filters, int $page, int $perPage): array
    {
        return $this->filteredQuery($filters)
            ->orderBy('l.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /** @param array{application?: ?ApiApplication, q?: ?string, method?: ?string} $filters */
    public function countFiltered(array $filters): int
    {
        return (int) $this->filteredQuery($filters)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param array{application?: ?ApiApplication, q?: ?string, method?: ?string} $filters */
    private function filteredQuery(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l');

        $application = $filters['application'] ?? null;
        if ($application instanceof ApiApplication) {
            $qb->andWhere('l.apiApplication = :app')->setParameter('app', $application->id, 'ulid');
        }

        $method = $filters['method'] ?? null;
        if (is_string($method) && $method !== '') {
            $qb->andWhere('l.method = :method')->setParameter('method', strtoupper($method));
        }

        $q = $filters['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $qb->andWhere('l.path LIKE :q OR l.routeName LIKE :q')
                ->setParameter('q', '%' . trim($q) . '%');
        }

        return $qb;
    }
}
