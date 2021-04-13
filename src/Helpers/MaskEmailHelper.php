<?php

namespace Crm\FamilyModule\Helpers;

class MaskEmailHelper
{
    public function process($email, $minLength = 3, $maxLength = 10, $mask = "***")
    {
        $atPos = strrpos($email, "@");
        $name = substr($email, 0, $atPos);
        $len = strlen($name);
        $domain = substr($email, $atPos);

        if (($len / 2) < $maxLength) {
            $maxLength = (int)($len / 2);
        }

        $shortenedEmail = (($len > $minLength) ? substr($name, 0, $maxLength) : "");
        return "{$shortenedEmail}{$mask}{$domain}";
    }
}
