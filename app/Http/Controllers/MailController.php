<?php

namespace App\Http\Controllers;

use App\Models\SubscriberEmail;
use App\Services\NotificationIntentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MailController extends Controller
{
    public function sendContactEmail(Request $request, NotificationIntentService $intents)
    {
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['first_name' => ['required', 'string', 'max:50'], 'last_name' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'], 'message' => ['required', 'string', 'max:10000']]);
        try { $intents->contact($data); }
        catch (\LogicException $error) { return response()->json(['success' => false, 'code' => 'contact_unavailable'], 503); }
        return response()->json(['success' => true, 'error' => false, 'delivery_state' => 'pending']);
    }

    public function subscribe(Request $request, NotificationIntentService $intents)
    {
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:255', 'unique:subscriber_emails,email']]);
        try { $intents->subscribe($data['email']); }
        catch (QueryException $error) { if (($error->errorInfo[1] ?? null) !== 1062) throw $error; throw ValidationException::withMessages(['email' => 'This email is already subscribed.']); }
        return response()->json(['success' => true, 'error' => false, 'delivery_state' => 'pending']);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        SubscriberEmail::where('email', $data['email'])->delete();
        return response()->view('mail.unsub')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'private, no-store');
    }
}
