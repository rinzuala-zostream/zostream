<?php

namespace Tests\Unit;

use App\Isp\Exceptions\AadhaarQrVerificationException;
use App\Isp\Services\AadhaarQrVerificationService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class AadhaarQrVerificationServiceTest extends TestCase
{
    private string $certificatePath;

    private \OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $request = openssl_csr_new(['commonName' => 'Synthetic UIDAI test key'], $this->privateKey);
        $certificate = openssl_csr_sign($request, null, $this->privateKey, 1);
        openssl_x509_export($certificate, $pem);

        $this->certificatePath = tempnam(sys_get_temp_dir(), 'aadhaar-cert-');
        file_put_contents($this->certificatePath, $pem);
    }

    protected function tearDown(): void
    {
        @unlink($this->certificatePath);
        parent::tearDown();
    }

    public function test_it_accepts_a_valid_digitally_signed_secure_qr_payload(): void
    {
        $payload = $this->signedPayload('V2'.str_repeat("\xffverified", 20));
        $service = new AadhaarQrVerificationService([$this->certificatePath]);

        $this->assertTrue($service->verifyPayload($payload));
    }

    public function test_it_accepts_a_valid_uncompressed_secure_qr_payload(): void
    {
        $payload = $this->signedPayload('V2'.str_repeat("\xffverified", 20), false);
        $service = new AadhaarQrVerificationService([$this->certificatePath]);

        $this->assertTrue($service->verifyPayload($payload));
    }

    public function test_it_rejects_a_tampered_secure_qr_payload(): void
    {
        $payload = $this->signedPayload('V2'.str_repeat("\xffverified", 20));
        $payload[strlen($payload) - 1] = $payload[strlen($payload) - 1] === '9' ? '8' : '9';
        $service = new AadhaarQrVerificationService([$this->certificatePath]);

        $this->assertFalse($service->verifyPayload($payload));
    }

    public function test_it_rejects_plain_text_and_non_aadhaar_qr_payloads(): void
    {
        $service = new AadhaarQrVerificationService([$this->certificatePath]);

        $this->assertFalse($service->verifyPayload('https://example.com'));
        $this->assertFalse($service->verifyPayload('1234567890'));
    }

    public function test_it_reads_an_uploaded_qr_before_rejecting_a_non_aadhaar_payload(): void
    {
        $fixture = dirname(__DIR__, 2).'/vendor/khanamiryan/qrcode-detector-decoder/tests/qrcodes/hello_world.png';
        $file = new UploadedFile($fixture, 'aadhaar-front.png', 'image/png', null, true);
        $service = new AadhaarQrVerificationService([$this->certificatePath]);

        $this->expectException(AadhaarQrVerificationException::class);
        $this->expectExceptionMessage('this QR is invalid or has been changed');

        $service->verifyUploadedFiles([$file]);
    }

    private function signedPayload(string $signedData, bool $compress = true): string
    {
        openssl_sign($signedData, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        $binary = $signedData.$signature;

        return $this->binaryToDecimal($compress ? gzencode($binary) : $binary);
    }

    private function binaryToDecimal(string $binary): string
    {
        $decimal = '0';
        foreach (unpack('C*', $binary) as $byte) {
            $carry = $byte;
            $result = '';
            for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
                $value = ((ord($decimal[$i]) - 48) * 256) + $carry;
                $result = ($value % 10).$result;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $result = ($carry % 10).$result;
                $carry = intdiv($carry, 10);
            }
            $decimal = ltrim($result, '0') ?: '0';
        }

        return $decimal;
    }
}
