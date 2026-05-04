<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class Coordinates
{
    #[ORM\Column(name: 'latitude', type: 'decimal', precision: 19, scale: 4, nullable: true)]
    #[Assert\Type(type: 'numeric', message: 'La latitude doit être un nombre.')]
    #[Assert\Range(notInRangeMessage: 'La latitude doit être comprise entre -90 et 90.', min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(name: 'longitude', type: 'decimal', precision: 19, scale: 4, nullable: true)]
    #[Assert\Type(type: 'numeric', message: 'La longitude doit être un nombre.')]
    #[Assert\Range(notInRangeMessage: 'La longitude doit être comprise entre -180 et 180.', min: -180, max: 180)]
    private ?string $longitude = null;

    public function __construct(float|string|null $latitude = null, float|string|null $longitude = null)
    {
        $this->setLatitude($latitude);
        $this->setLongitude($longitude);
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(float|string|null $latitude): self
    {
        $this->latitude = $latitude !== null ? (string) $latitude : null;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(float|string|null $longitude): self
    {
        $this->longitude = $longitude !== null ? (string) $longitude : null;

        return $this;
    }
}
