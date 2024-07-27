<?php

namespace App\Entity;

use App\Repository\ImageRepository;

use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Ulid;

use Vich\UploaderBundle\Mapping\Annotation\Uploadable;
use Vich\UploaderBundle\Mapping\Annotation\UploadableField;

#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[Uploadable]
class Image
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['images'])]
    private ?Ulid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['images'])]
    private ?string $author = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['images'])]
    private ?Bookcase $bookcase = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['images'])]
    private ?User $uploadedBy = null;

    #[UploadableField(mapping: 'images', fileNameProperty: 'filename', size: 'imageSize')]
    #[Groups(['images'])]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255)]
    #[Groups(['images'])]
    private ?string $filename = null;

    #[ORM\Column(length: 255)]
    #[Groups(['images'])]
    private ?string $filenameThumbnail = null;

    #[ORM\Column]
    #[Groups(['images'])]
    private ?int $imageSize = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['images'])]
    private ?int $rotation = null;

    #[ORM\Column(type: 'datetime')]
    #[Groups(['images'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFilenameThumbnail(): ?string
    {
        return $this->filenameThumbnail;
    }

    public function setFilenameThumbnail(string $filenameThumbnail): self
    {
        $this->filenameThumbnail = $filenameThumbnail;

        return $this;
    }

    public function getBookcase(): ?Bookcase
    {
        return $this->bookcase;
    }

    public function setBookcase(?Bookcase $bookcase): self
    {
        $this->bookcase = $bookcase;

        return $this;
    }

    public function getRotation(): ?int
    {
        return $this->rotation;
    }

    public function setRotation(?int $rotation): self
    {
        $this->rotation = $rotation;

        return $this;
    }

    public function setImageFile(?File $imageFile = null): self
    {
        $this->imageFile = $imageFile;

        if (null !== $imageFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageSize(?int $imageSize): self
    {
        $this->imageSize = $imageSize;

        return $this;
    }

    public function getImageSize(): ?int
    {
        return $this->imageSize;
    }

    public function getUniqueFileName(): string
    {
        return 'bookcase_' . $this->getId() . '_' . new Ulid();
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
