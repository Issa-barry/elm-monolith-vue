<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    public function test_store_as_webp_stores_a_valid_image(): void
    {
        Storage::fake('public');

        $path = (new ImageService)->storeAsWebp(
            UploadedFile::fake()->image('vehicule.jpg', 400, 300),
            'propositions-vehicules'
        );

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);
    }

    /**
     * Régression : un fichier que Laravel valide comme `image` (getimagesize() le
     * reconnaît) mais que le décodeur GD/Intervention Image refuse de lire pixel par
     * pixel plantait storeResized() en 500 (DecoderException non interceptée) — cf.
     * ClientDashboardController::storeVehicleProposal(). Doit désormais remonter une
     * erreur de validation exploitable côté formulaire, jamais un crash serveur.
     */
    public function test_store_as_webp_converts_undecodable_image_into_validation_error(): void
    {
        Storage::fake('public');

        // PNG grayscale+alpha minimal (68 octets) : getimagesize() le reconnaît comme
        // PNG valide, mais GD échoue à le décoder réellement (imagecreatefrompng()
        // retourne false) — reproduit fidèlement le fichier qui a fait planter l'E2E.
        $undecodable = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3Zk0gAAAAASUVORK5CYII=');
        $path = tempnam(sys_get_temp_dir(), 'undecodable').'.png';
        file_put_contents($path, $undecodable);
        $file = new UploadedFile($path, 'undecodable.png', 'image/png', null, true);

        try {
            $this->expectException(ValidationException::class);
            (new ImageService)->storeAsWebp($file, 'propositions-vehicules');
        } finally {
            @unlink($path);
        }
    }
}
