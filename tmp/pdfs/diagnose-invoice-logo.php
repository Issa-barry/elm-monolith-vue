<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$factures = App\Models\FactureFournisseur::where('reference','FAF-101026-001')->get(['id','organization_id']);
$orgIds = $factures->pluck('organization_id')->unique();
$organisations = App\Models\Organization::whereIn('id',$orgIds)->get(['id','name','logo_path']);
foreach ($organisations as $org) {
 echo json_encode(['name'=>$org->name,'logo_path'=>$org->logo_path,'logo_url'=>$org->logo_url,'file_exists'=>$org->logo_path ? Illuminate\Support\Facades\Storage::disk('public')->exists($org->logo_path) : false,'public_link_exists'=>$org->logo_path ? is_file(public_path('storage/'.$org->logo_path)) : false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}
echo 'Factures trouvées: '.$factures->count().PHP_EOL;
echo 'Organisations: '.App\Models\Organization::count().PHP_EOL;
echo 'GD WebP: '.(function_exists('imagecreatefromwebp') ? 'oui' : 'non').PHP_EOL;
