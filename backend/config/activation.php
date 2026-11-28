<?php
return [
    // Set to the actual public frontend address, including /aktivasi-akun.
    'public_url' => env('SIAKAD_ACTIVATION_URL'),
    // Optional exact RFC1918 IPv4 host, accepted over HTTP only in local/testing.
    'lan_host' => env('SIAKAD_ACTIVATION_LAN_HOST'),
];
