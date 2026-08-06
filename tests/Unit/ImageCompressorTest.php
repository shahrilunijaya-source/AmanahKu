<?php

namespace Tests\Unit;

use App\Support\ImageCompressor;
use Tests\TestCase;

class ImageCompressorTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_large_jpeg_is_resized_and_shrunk(): void
    {
        $im = imagecreatetruecolor(3000, 1500);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50));
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.jpg';
        imagejpeg($im, $this->path, 100);
        imagedestroy($im);

        $originalSize = filesize($this->path);

        ImageCompressor::compress($this->path, 'image/jpeg');

        [$width, $height] = getimagesize($this->path);
        $this->assertSame(2000, $width);
        $this->assertSame(1000, $height);
        $this->assertLessThan($originalSize, filesize($this->path));
    }

    public function test_small_image_is_not_upscaled(): void
    {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 10, 10));
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.jpg';
        imagejpeg($im, $this->path, 100);
        imagedestroy($im);

        ImageCompressor::compress($this->path, 'image/jpeg');

        [$width, $height] = getimagesize($this->path);
        $this->assertSame(400, $width);
        $this->assertSame(300, $height);
    }

    public function test_gif_is_left_untouched(): void
    {
        $im = imagecreate(10, 10);
        imagecolorallocate($im, 0, 0, 0);
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.gif';
        imagegif($im, $this->path);
        imagedestroy($im);

        $before = file_get_contents($this->path);
        ImageCompressor::compress($this->path, 'image/gif');
        $after = file_get_contents($this->path);

        $this->assertSame($before, $after);
    }
}
