<?php

namespace App\Entity;

use App\Repository\ImageRepository;

use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Ulid;

use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[Uploadable]
class Image
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['images'])]
    public ?Ulid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['images'])]
    public ?string $author = null;

    // Screen-reader description of the photo (alt text). Optional.
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['images'])]
    public ?string $altText = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['images'])]
    public ?Bookcase $bookcase = null;

    // Nullable so a user can delete their account while their uploaded images are
    // kept (the personal link is removed, the photo stays).
    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['images'])]
    public ?User $uploadedBy = null;

    #[UploadableField(mapping: 'images', fileNameProperty: 'filename', size: 'imageSize')]
    #[Groups(['images'])]
    public ?File $imageFile = null;

    #[ORM\Column(length: 255)]
    #[Groups(['images'])]
    public ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['images'])]
    public ?string $filenameThumbnail = null;

    #[ORM\Column]
    #[Groups(['images'])]
    public ?int $imageSize = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['images'])]
    public ?int $rotation = null;

    #[ORM\Column(type: 'datetime')]
    #[Groups(['images'])]
    public ?\DateTimeInterface $updatedAt = null;

    public function setImageFile(?File $imageFile = null): self
    {
        $this->imageFile = $imageFile;

        if (null !== $imageFile) {
            $this->updatedAt = new \DateTime();
        }

        return $this;
    }

    public function getUniqueFileName(): string
    {
        return 'bookcase_' . $this->id . '_' . new Ulid();
    }
}
