<?php

namespace App\Isp\Services;

use App\Isp\Exceptions\AadhaarQrVerificationException;
use Illuminate\Http\UploadedFile;
use OpenSSLAsymmetricKey;
use Throwable;
use Zxing\QrReader;

class AadhaarQrVerificationService
{
    /**
     * @param  array<int, string>|null  $certificatePaths
     */
    public function __construct(private readonly ?array $certificatePaths = null) {}

    /**
     * @param  array<int, UploadedFile|null>  $files
     */
    public function verifyUploadedFiles(array $files): void
    {
        $foundQr = false;

        foreach (array_filter($files) as $file) {
            foreach ($this->readQrPayloads($file->getRealPath()) as $payload) {
                $foundQr = true;
                if ($this->verifyPayload($payload)) {
                    return;
                }
            }
        }

        if ($foundQr) {
            throw new AadhaarQrVerificationException(
                'Aadhaar verification failed: this QR is invalid or has been changed. Upload clear images of the original UIDAI-issued Aadhaar card. No customer or payment was created.',
            );
        }

        throw new AadhaarQrVerificationException(
            'Aadhaar verification failed: the Secure QR could not be read. Retake clear, uncropped front and back photos with the complete QR visible. No customer or payment was created.',
        );
    }

    public function verifyPayload(string $payload): bool
    {
        $payload = trim($payload);
        if ($payload === '' || preg_match('/\D/', $payload)) {
            return false;
        }

        $encoded = $this->decimalToBinary($payload);
        if (str_starts_with($encoded, "\x1f\x8b")) {
            set_error_handler(static fn (): bool => true);
            try {
                $decoded = gzdecode($encoded);
            } finally {
                restore_error_handler();
            }
        } else {
            // Secure QR is normally gzip-compressed, but UIDAI readers also
            // encounter unpacked variants. Authenticity still depends on the
            // same trailing RSA signature, so accepting raw bytes does not
            // weaken verification.
            $decoded = $encoded;
        }

        if ($decoded === false || strlen($decoded) < 300) {
            return false;
        }

        foreach ($this->publicKeys() as $key) {
            $details = openssl_pkey_get_details($key);
            $signatureLength = isset($details['bits']) ? (int) ceil($details['bits'] / 8) : 256;
            if (strlen($decoded) <= $signatureLength) {
                continue;
            }

            $signedData = substr($decoded, 0, -$signatureLength);
            $signature = substr($decoded, -$signatureLength);
            if (openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decoder stores one PHP value for every image pixel. Feeding a modern
     * phone photo to it directly can therefore consume hundreds of megabytes.
     * Decode small, overlapping crops so a large Aadhaar photo stays safely
     * below the PHP memory limit while the QR retains enough detail to scan.
     *
     * @return array<int, string>
     */
    private function readQrPayloads(string $path): array
    {
        $candidatePaths = $this->buildQrCandidateImages($path);
        $payloads = [];

        try {
            foreach ($candidatePaths as $candidatePath) {
                try {
                    $reader = new QrReader($candidatePath);
                    $payload = $reader->text();
                    unset($reader);

                    if (is_string($payload) && $payload !== '' && ! in_array($payload, $payloads, true)) {
                        $payloads[] = $payload;
                    }
                } catch (Throwable) {
                    // One crop may miss the QR; the remaining crops can still find it.
                } finally {
                    gc_collect_cycles();
                }
            }
        } finally {
            foreach ($candidatePaths as $candidatePath) {
                @unlink($candidatePath);
            }
        }

        return $payloads;
    }

    /**
     * @return array<int, string>
     */
    private function buildQrCandidateImages(string $path): array
    {
        $size = @getimagesize($path);
        if (! $size || ! isset($size[0], $size[1], $size[2])) {
            return [];
        }

        $width = (int) $size[0];
        $height = (int) $size[1];
        if ($width < 1 || $height < 1) {
            return [];
        }

        // Loading the source into GD needs roughly four bytes per pixel. Reject
        // unusually large originals before allocation instead of causing a 500.
        if (($width * $height) > 24_000_000) {
            throw new AadhaarQrVerificationException(
                'Aadhaar verification failed: the uploaded photo resolution is too large to process safely. Resize it below 24 megapixels or retake the photo, then upload again. No customer or payment was created.',
            );
        }

        set_error_handler(static fn (): bool => true);
        try {
            $source = match ($size[2]) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                IMAGETYPE_PNG => imagecreatefrompng($path),
                default => false,
            };
        } catch (Throwable) {
            $source = false;
        } finally {
            restore_error_handler();
        }

        if ($source === false) {
            return [];
        }

        // The overlapping regions cover common Aadhaar layouts without knowing
        // whether the uploaded photo is portrait, landscape, front, or back.
        $regions = [
            [0.00, 0.00, 1.00, 1.00],
            [0.38, 0.00, 0.62, 1.00],
            [0.00, 0.00, 0.62, 1.00],
            [0.00, 0.38, 1.00, 0.62],
            [0.00, 0.00, 1.00, 0.62],
            [0.00, 0.00, 0.58, 0.58],
            [0.42, 0.00, 0.58, 0.58],
            [0.00, 0.42, 0.58, 0.58],
            [0.42, 0.42, 0.58, 0.58],
        ];
        $candidatePaths = [];

        try {
            foreach ($regions as [$xRatio, $yRatio, $widthRatio, $heightRatio]) {
                $cropX = (int) floor($width * $xRatio);
                $cropY = (int) floor($height * $yRatio);
                $cropWidth = max(1, min($width - $cropX, (int) ceil($width * $widthRatio)));
                $cropHeight = max(1, min($height - $cropY, (int) ceil($height * $heightRatio)));

                // Keep each decoder input under 420k pixels. This is the main
                // guard against the decoder's per-pixel PHP array exhausting RAM.
                $scale = min(
                    1,
                    900 / max($cropWidth, $cropHeight),
                    sqrt(420_000 / ($cropWidth * $cropHeight)),
                );
                $targetWidth = max(1, (int) floor($cropWidth * $scale));
                $targetHeight = max(1, (int) floor($cropHeight * $scale));
                $candidate = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($candidate === false) {
                    continue;
                }

                try {
                    if (! imagecopyresampled(
                        $candidate,
                        $source,
                        0,
                        0,
                        $cropX,
                        $cropY,
                        $targetWidth,
                        $targetHeight,
                        $cropWidth,
                        $cropHeight,
                    )) {
                        continue;
                    }

                    imagefilter($candidate, IMG_FILTER_GRAYSCALE);
                    $candidatePath = tempnam(sys_get_temp_dir(), 'aadhaar-qr-');
                    if ($candidatePath !== false && imagepng($candidate, $candidatePath, 6)) {
                        $candidatePaths[] = $candidatePath;
                    } elseif ($candidatePath !== false) {
                        @unlink($candidatePath);
                    }
                } finally {
                    unset($candidate);
                }
            }
        } finally {
            unset($source);
        }

        return $candidatePaths;
    }

    /**
     * @return array<int, OpenSSLAsymmetricKey>
     */
    private function publicKeys(): array
    {
        $keys = [];
        $certificates = $this->certificatePaths ?? (glob(resource_path('certificates/uidai/*.cer')) ?: []);
        foreach ($certificates as $certificate) {
            $der = file_get_contents($certificate);
            if ($der === false) {
                continue;
            }

            $pem = str_contains($der, '-----BEGIN CERTIFICATE-----')
                ? $der
                : "-----BEGIN CERTIFICATE-----\n"
                    .chunk_split(base64_encode($der), 64, "\n")
                    ."-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($pem);
            if ($key instanceof OpenSSLAsymmetricKey) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            throw new AadhaarQrVerificationException('Aadhaar verification is temporarily unavailable on the server. No customer or payment was created. Please contact the administrator.');
        }

        return $keys;
    }

    private function decimalToBinary(string $decimal): string
    {
        $decimal = ltrim($decimal, '0');
        if ($decimal === '') {
            return "\0";
        }

        $bytes = '';
        while ($decimal !== '0') {
            $quotient = '';
            $remainder = 0;
            $length = strlen($decimal);
            for ($i = 0; $i < $length; $i++) {
                $value = ($remainder * 10) + (ord($decimal[$i]) - 48);
                $digit = intdiv($value, 256);
                if ($quotient !== '' || $digit !== 0) {
                    $quotient .= (string) $digit;
                }
                $remainder = $value % 256;
            }

            $bytes .= chr($remainder);
            $decimal = $quotient === '' ? '0' : $quotient;
        }

        return strrev($bytes);
    }
}
