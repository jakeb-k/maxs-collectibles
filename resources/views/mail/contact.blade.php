<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Customer message</title></head><body style="font-family:Arial,sans-serif;line-height:1.6">
<h1>{{ config('app.name') }} — Customer message</h1>
<p>From: {{ $data['first_name'] }} {{ $data['last_name'] ?? '' }}</p><p>Email: {{ $data['email'] }}</p>
<p style="white-space:pre-wrap">{{ $data['message'] }}</p>
</body></html>
