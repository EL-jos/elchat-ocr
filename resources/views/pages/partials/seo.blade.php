@php
    $seo = trans('site.seo.' . $page);
    $seoUrl = \App\Support\SiteLocale::urlForPage($page, app()->getLocale());
    $language = \App\Support\SiteLocale::languages()[app()->getLocale()] ?? \App\Support\SiteLocale::languages()['fr'];
@endphp

<title>{{ $seo['title'] }}</title>
<meta name="title" content="{{ $seo['title'] }}">
<meta name="description" content="{{ $seo['description'] }}">
<meta name="author" content="ELChat">
<meta name="robots" content="index, follow">
<meta name="language" content="{{ app()->getLocale() }}">
<link rel="canonical" href="{{ $seoUrl }}">

<meta property="og:type" content="website">
<meta property="og:locale" content="{{ str_replace('-', '_', $language['hreflang']) }}">
<meta property="og:site_name" content="ELChat">
<meta property="og:title" content="{{ $seo['og_title'] }}">
<meta property="og:description" content="{{ $seo['og_description'] }}">
<meta property="og:url" content="{{ $seoUrl }}">
<meta property="og:image" content="https://elchat.io/assets/images/sub-banner-img.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['twitter_title'] }}">
<meta name="twitter:description" content="{{ $seo['twitter_description'] }}">
<meta name="twitter:image" content="https://elchat.io/assets/images/sub-banner-img.png">
