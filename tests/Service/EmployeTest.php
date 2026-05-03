<?php

namespace App\Tests\Service;

use App\Entity\Employe;
use App\Service\EmployeManager;
use PHPUnit\Framework\TestCase;

class EmployeTest extends TestCase
{
    public function testValidEmploye(): void
    {
        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();

        $this->assertTrue($manager->validate($employe));
    }

    public function testEmployeWithoutNom(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithoutPrenom(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithInvalidEmailFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('email_invalide');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithNonGmailAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@yahoo.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithInvalidTelephone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(1234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithoutPoste(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setRole('Employe');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testEmployeWithoutRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');

        $manager = new EmployeManager();
        $manager->validate($employe);
    }

    public function testDateEmbaucheTodayOrPastIsValid(): void
    {
        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');
        $employe->setDateEmbauche(new \DateTime('today'));

        $manager = new EmployeManager();
        $this->assertTrue($manager->validate($employe));
    }

    public function testDateEmbaucheFutureIsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $employe = new Employe();
        $employe->setNom('Dupont');
        $employe->setPrenom('Alice');
        $employe->setEmail('alice.dupont@gmail.com');
        $employe->setTelephone(61234567);
        $employe->setPoste('Developpeuse');
        $employe->setRole('Employe');
        $employe->setDateEmbauche(new \DateTime('+1 day'));

        $manager = new EmployeManager();
        $manager->validate($employe);
    }
}
