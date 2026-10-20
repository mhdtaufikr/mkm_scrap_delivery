<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(12)->withQueryString(),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return redirect()->route('documents.show', $item->data['document_id']);
    }
}
