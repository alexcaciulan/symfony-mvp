<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-test-users',
    description: 'Create test users for development (idempotent)',
)]
class CreateTestUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $testUsers = [
            [
                'email' => 'admin@test.com',
                'firstName' => 'Admin',
                'lastName' => 'Test',
                'roles' => ['ROLE_ADMIN'],
                'type' => UserType::ADMIN,
            ],
            [
                'email' => 'creditor-pf@test.com',
                'firstName' => 'Ion',
                'lastName' => 'Popescu',
                'roles' => ['ROLE_USER', 'ROLE_CREDITOR'],
                'type' => UserType::PF,
                'cnp' => '1850101123456',
                'phone' => '0721000001',
                'city' => 'București',
                'county' => 'București',
                'street' => 'Strada Victoriei',
                'streetNumber' => '10',
            ],
            [
                'email' => 'creditor-pj@test.com',
                'firstName' => 'Maria',
                'lastName' => 'Ionescu',
                'roles' => ['ROLE_USER', 'ROLE_CREDITOR'],
                'type' => UserType::PJ,
                'cui' => 'RO12345678',
                'companyName' => 'SC Test SRL',
                'phone' => '0721000002',
                'city' => 'Cluj-Napoca',
                'county' => 'Cluj',
                'street' => 'Strada Memorandumului',
                'streetNumber' => '5',
            ],
            [
                'email' => 'avocat@test.com',
                'firstName' => 'Andrei',
                'lastName' => 'Georgescu',
                'roles' => ['ROLE_USER', 'ROLE_CREDITOR'],
                'type' => UserType::AVOCAT,
                'barNumber' => 'B-12345',
                'phone' => '0721000003',
                'city' => 'Timișoara',
                'county' => 'Timiș',
            ],
            [
                'email' => 'user@test.com',
                'firstName' => 'Elena',
                'lastName' => 'Dumitrescu',
                'roles' => ['ROLE_USER'],
                'type' => UserType::PF,
                'phone' => '0721000004',
                'city' => 'Iași',
                'county' => 'Iași',
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($testUsers as $data) {
            $existing = $this->userRepository->findOneBy(['email' => $data['email']]);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setRoles($data['roles']);
            $user->setType($data['type']);
            $user->setIsVerified(true);

            if (isset($data['cnp'])) {
                $user->setCnp($data['cnp']);
            }
            if (isset($data['cui'])) {
                $user->setCui($data['cui']);
            }
            if (isset($data['companyName'])) {
                $user->setCompanyName($data['companyName']);
            }
            if (isset($data['barNumber'])) {
                $user->setBarNumber($data['barNumber']);
            }
            if (isset($data['phone'])) {
                $user->setPhone($data['phone']);
            }
            if (isset($data['city'])) {
                $user->setCity($data['city']);
            }
            if (isset($data['county'])) {
                $user->setCounty($data['county']);
            }
            if (isset($data['street'])) {
                $user->setStreet($data['street']);
            }
            if (isset($data['streetNumber'])) {
                $user->setStreetNumber($data['streetNumber']);
            }

            $hashedPassword = $this->passwordHasher->hashPassword($user, 'password');
            $user->setPassword($hashedPassword);

            $this->em->persist($user);
            $created++;
        }

        $this->em->flush();

        $io->success(sprintf('Test users: %d created, %d skipped (already exist).', $created, $skipped));

        return Command::SUCCESS;
    }
}
