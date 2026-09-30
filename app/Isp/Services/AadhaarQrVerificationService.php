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
            $payload = $this->readQrPayload($file->getRealPath());
            if ($payload === null) {
                continue;
            }

            $foundQr = true;
            if ($this->verifyPayload($payload)) {
                return;
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

        $compressed = $this->decimalToBinary($payload);
        if (! str_starts_with($compressed, "\x1f\x8b")) {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $decoded = gzdecode($compressed);
        } finally {
            restore_error_handler();
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

    private function readQrPayload(string $path): ?string
    {
        try {
            $size = @getimagesize($path);
            if (! $size || ($size[0] * $size[1]) > 50_000_000) {
                return null;
            }

            $payload = (new QrReader($path))->text();

            return is_string($payload) && $payload !== '' ? $payload : null;
        } catch (Throwable) {
            return null;
        }
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
