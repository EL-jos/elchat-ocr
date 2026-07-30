@extends('pages.layouts.blank')
@section('title', 'Paiement réussi — ELChat')

@section('main-content')
    <div style="min-height:100vh; background:#0f172a; display:flex; align-items:center; justify-content:center; padding:40px 24px;">
        <div style="max-width:520px; width:100%; background:#1e293b; border:1px solid #334155; border-radius:20px; padding:48px 40px; text-align:center;">

            {{-- Icône succès --}}
            <div style="width:72px; height:72px; background:rgba(34,197,94,.15); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 24px; border:2px solid rgba(34,197,94,.3);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>

            <h1 style="font-size:26px; font-weight:800; color:#f1f5f9; margin-bottom:8px; letter-spacing:-.5px;">
                Paiement confirmé !
            </h1>
            <p style="color:#94a3b8; font-size:15px; margin-bottom:32px; line-height:1.6;">
                Votre abonnement
                <strong style="color:#f1f5f9;">{{ $plan?->name ?? 'ELChat' }}</strong>
                est maintenant actif.
                Un email de confirmation vous a été envoyé.
            </p>

            {{-- Détails --}}
            <div style="background:#0f172a; border:1px solid #334155; border-radius:10px; padding:20px; text-align:left; margin-bottom:28px;">
                <div style="display:flex; justify-content:space-between; padding:7px 0; font-size:14px; border-bottom:1px solid #1e293b;">
                    <span style="color:#94a3b8;">Plan</span>
                    <strong style="color:#f1f5f9;">{{ $plan?->name ?? '—' }}</strong>
                </div>
                <div style="display:flex; justify-content:space-between; padding:7px 0; font-size:14px; border-bottom:1px solid #1e293b;">
                    <span style="color:#94a3b8;">Facturation</span>
                    <strong style="color:#f1f5f9;">{{ isset($billingCycle) && $billingCycle === 'annual' ? 'Annuelle' : 'Mensuelle' }}</strong>
                </div>
                <div style="display:flex; justify-content:space-between; padding:7px 0; font-size:14px;">
                    <span style="color:#94a3b8;">Moyen de paiement</span>
                    <strong style="color:#f1f5f9; display:flex; align-items:center; gap:6px;">
                        @if(($provider ?? 'stripe') === 'paypal')
                            {{-- Badge PayPal --}}
                            <span style="background:#ffc439; color:#003087; font-size:11px; font-weight:800; padding:2px 8px; border-radius:4px;">PayPal</span>
                        @else
                            {{-- Badge Carte --}}
                            <span style="background:#3b82f6; color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">💳 Carte</span>
                        @endif
                    </strong>
                </div>
            </div>

            {{-- CTA --}}
            <a href="/app"
               style="display:block; background:#3b82f6; color:#fff; text-decoration:none; padding:14px 28px; border-radius:9px; font-weight:700; font-size:15px; margin-bottom:16px;"
               onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                Accéder à mon tableau de bord →
            </a>

            {{-- Lien portail — Stripe uniquement (PayPal a son propre portail) --}}
            @if(($provider ?? 'stripe') === 'stripe')
                <a href="/billing/portal" style="display:block; color:#64748b; font-size:13px; text-decoration:none; margin-top:8px;">
                    Gérer mon abonnement &amp; factures
                </a>
            @else
                <p style="color:#64748b; font-size:13px; margin-top:8px;">
                    Gérez votre abonnement directement depuis votre compte PayPal.
                </p>
            @endif

        </div>
    </div>
@endsection
