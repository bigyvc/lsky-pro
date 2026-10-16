{{-- Shared browser and iPhone icons; fingerprinted URLs invalidate the previous domain icon. --}}
<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v={{ \App\Utils::assetVersion('favicon.ico') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}?v={{ \App\Utils::assetVersion('favicon-32x32.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}?v={{ \App\Utils::assetVersion('apple-touch-icon.png') }}">
