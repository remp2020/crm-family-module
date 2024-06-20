<?php

namespace Crm\FamilyModule\Measurements;

use Crm\ApplicationModule\Models\Measurements\BaseMeasurement;
use Crm\ApplicationModule\Models\Measurements\Criteria;
use Crm\ApplicationModule\Models\Measurements\Point;
use Crm\ApplicationModule\Models\Measurements\Series;

class ActivePaidAccessesMeasurement extends BaseMeasurement
{
    public const CODE = 'subscriptions.active_paid_accesses';

    public function calculate(Criteria $criteria): Series
    {
        $series = $criteria->getEmptySeries();

        $date = clone $criteria->getFrom();
        while ($date <= $criteria->getTo()) {
            $next = $criteria->getAggregation()->nextDate($date);

            $total = $this->countActivePayingSubscribers($date, $next) + $this->countUnusedFamilyRequests($date, $next);

            $point = new Point($criteria->getAggregation(), $total, clone $date);
            $series->setPoint($point);

            $date = $next;
        }
        return $series;
    }

    private function countActivePayingSubscribers($date, $next): int
    {
        $query = "
                SELECT is_paid, COUNT(DISTINCT subscriptions.user_id) AS count
                FROM subscriptions
                JOIN users ON users.id = subscriptions.user_id
                WHERE ?
                GROUP BY is_paid
            ";

        $rows = $this->db()->query(
            $query,
            [
                'start_time <' => $next,
                'end_time >=' => $date,
                'users.active' => 1,
                'subscription_type_id NOT' => $this->db()::literal(
                    'IN (SELECT master_subscription_type_id FROM family_subscription_types)'
                ),
            ],
        );

        foreach ($rows as $row) {
            if ($row->is_paid === 1) {
                return $row->count;
            }
        }

        return 0;
    }

    private function countUnusedFamilyRequests($date, $next): int
    {
        $query = "
                SELECT COUNT(*) AS count 
                FROM family_requests
                JOIN subscriptions ON family_requests.master_subscription_id = subscriptions.id
                WHERE ?
            ";

        $row = $this->db()->fetch(
            $query,
            [
                'subscriptions.start_time <' => $next,
                'subscriptions.end_time >=' => $date,
                'family_requests.created_at <' => $next,
                $this->db()::literal('?or', [
                    'accepted_at' => null,
                    'accepted_at >' => $next,
                ]),
                $this->db()::literal('?or', [
                    'canceled_at' => null,
                    'canceled_at <' => $next,
                ]),
            ],
        );

        return $row->count;
    }
}
