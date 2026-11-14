<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;
use BaconQrCode\Writer;
use BaconQrCode\Renderer\Image\Png;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd; // fallback if GD not available
use Illuminate\Support\Str;

class QrCodeController extends Controller
{
    // Owner generates a QR code (only role_id 2)
    public function generate(Request $request): Response
    {
        $user = Auth::user();
        if ($user->role_id !== 2) { // Owner role
            return response()->json(['msg' => 'Forbidden'], 403);
        }

        $url = 'https://brewspotph.online/pos';
        $token = (string) Str::uuid();
        $fullUrl = $url . '?qr=' . $token;

        // Generate PNG using BaconQrCode
        $renderer = new Png();
        $renderer->setWidth(300);
        $writer = new Writer($renderer);
        $imageData = $writer->writeString($fullUrl);

        // Ensure storage directory exists
        $storageDir = storage_path('app/public/qrcodes');
        if (!file_exists($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $filename = $token . '.png';
        $fullPath = $storageDir . '/' . $filename;
        file_put_contents($fullPath, $imageData);

        $qr = QrCode::create([
            'token'   => $token,
            'owner_id'=> $user->id,
            'url'     => $fullUrl,
            'path'    => 'storage/qrcodes/' . $filename,
        ]);

        return response()->json($qr, 201);
    }

    // Manager (or Owner/Admin) lists QR codes
    public function index(): Response
    {
        $user = Auth::user();
        if (!in_array($user->role_id, [1, 2, 3])) { // admin, owner, manager
            return response()->json(['msg' => 'Forbidden'], 403);
        }

        $query = QrCode::query();
        if ($user->role_id === 3) { // manager sees only owners' QR codes
            $ownerIds = User::where('role_id', 2)->pluck('id');
            $query->whereIn('owner_id', $ownerIds);
        }
        $codes = $query->with('owner:id,firstname,lastname,username')->get();
        return response()->json($codes);
    }
}
?>