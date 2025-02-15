<?php

namespace RiseTechApps\ApiKey\Features;

class AvatarGenerator
{
    public function generateAvatar($name): string
    {
        $width = 200;
        $height = 200;

        $bgColor = [211, 211, 211];

        $image = imagecreatetruecolor($width, $height);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        imagefill($image, 0, 0, $transparent);

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $circleColorAlloc = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);

        imagefilledellipse($image, $width / 2, $height / 2, $width, $height, $circleColorAlloc);

        $initials = $this->getInitials($name);

        $textColor = [0, 0, 0];
        $textColorAlloc = imagecolorallocate($image, $textColor[0], $textColor[1], $textColor[2]);

        $fontSize = 40;
        $fontFile = __DIR__ . '/roboto.ttf';

        $bbox = imagettfbbox($fontSize, 0, $fontFile, $initials);
        $textX = ($width - $bbox[2]) / 2;
        $textY = ($height - $bbox[1]) / 2 + ($bbox[1] - $bbox[5]) / 2;

        imagettftext($image, $fontSize, 0, $textX, $textY, $textColorAlloc, $fontFile, $initials);

        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);

        return $imageData;
    }

    private function getInitials($name): string
    {
        $initials = '';
        foreach (explode(' ', $name) as $part) {
            $initials .= strtoupper($part[0]);
        }
        return $initials;
    }
}
