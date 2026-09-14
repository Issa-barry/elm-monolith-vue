<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Client\QrPayloadResolver;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer as QrWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

class QrCodeClientDashboardController extends Controller
{
    public function __construct(
        private readonly QrPayloadResolver $qrPayloadResolver,
    ) {}

    /**
     * Repli vers le dashboard (jamais null) : cet endpoint génère une image QR toujours
     * affichable, contrairement à MeController::qr_payload qui expose explicitement `null` à un
     * frontend capable d'afficher un état "indisponible".
     */
    private function resolveQrPayload(User $user): string
    {
        return $this->qrPayloadResolver->resolveForUser($user) ?? route('dashboard');
    }

    public function __invoke(Request $request): HttpResponse
    {
        $user = $request->user();
        $payload = $this->resolveQrPayload($user);

        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd
        );
        $writer = new QrWriter($renderer);
        $svg = $writer->writeString($payload);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
