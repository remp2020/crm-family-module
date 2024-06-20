<?php

namespace Crm\FamilyModule\Seeders;

use Crm\ApplicationModule\Repositories\MeasurementsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\ApplicationModule\Seeders\MeasurementsTrait;
use Crm\FamilyModule\Measurements\ActivePaidAccessesMeasurement;
use Symfony\Component\Console\Output\OutputInterface;

class MeasurementsSeeder implements ISeeder
{
    use MeasurementsTrait;

    private MeasurementsRepository $measurementsRepository;

    public function __construct(MeasurementsRepository $measurementsRepository)
    {
        $this->measurementsRepository = $measurementsRepository;
    }

    public function seed(OutputInterface $output)
    {
        $this->addMeasurement(
            $output,
            ActivePaidAccessesMeasurement::CODE,
            'family.measurements.active_paid_accesses.title',
            'family.measurements.active_paid_accesses.description',
        );
    }
}
