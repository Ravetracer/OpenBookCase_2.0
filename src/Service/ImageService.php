<?php

namespace App\Service;

use App\Entity\Image;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ImageService
{
    private const MAX_LONG_SIDE = 1000;

    private string $imageDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->imageDir = $projectDir . '/public/images/';
    }

    public function processUpload(Image $image): void
    {
        $path = $this->imageDir . $image->filename;
        $img = (new ImageManager(new Driver()))->decodePath($path);

        $img->orient();
        $img->scaleDown(self::MAX_LONG_SIDE, self::MAX_LONG_SIDE);
        $img->save($path);
    }

    public function rotate(Image $image, bool $clockwise): void
    {
        $path = $this->imageDir . $image->filename;
        $img = (new ImageManager(new Driver()))->decodePath($path);

        // intervention/image v4: positive angle = clockwise
        $img->rotate($clockwise ? 90 : -90);
        $img->save($path);
    }
}
