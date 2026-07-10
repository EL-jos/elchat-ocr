<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nouveau message - ELChat</title>

    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

</head>

<body style="
margin:0;
padding:0;
background:#f4f7fb;
font-family:'Urbanist',Arial,Helvetica,sans-serif;
">

<table role="presentation"
       cellpadding="0"
       cellspacing="0"
       width="100%"
       style="background:#f4f7fb;padding:40px 15px;">

    <tr>

        <td align="center">

            <table
                role="presentation"
                cellpadding="0"
                cellspacing="0"
                width="720"
                style="
        max-width:720px;
        width:100%;
        background:#ffffff;
        border-radius:18px;
        overflow:hidden;
        border:1px solid #edf1f6;
        box-shadow:0 10px 40px rgba(0,0,0,.05);
        ">

                <!-- ===================== -->
                <!-- HEADER -->
                <!-- ===================== -->

                <tr>

                    <td
                        align="center"
                        style="
                background:linear-gradient(135deg,#020202,#1e1e1e);
                padding:50px 30px;
                ">

                        <div style="
                    width:100px;
                    height:100px;
                    border-radius:18px;
                    background:black;
                    display:inline-block;
                    line-height:72px;
                    text-align:center;
                    font-size:32px;
                    font-weight:800;
                    color:#2777fc;
                    margin-bottom:20px;
                    padding: 15px;
                    ">

                            <img width="100px" height="100px" src="{{ asset('assets/images/logo.svg') }}" alt="ELChat">

                        </div>

                        <p style="
                margin:14px 0 0;
                color:rgba(255,255,255,.92);
                font-size:16px;
                line-height:28px;
                max-width:520px;
                ">

                            Nouvelle demande reçue depuis le formulaire de contact
                            du site internet.

                        </p>

                    </td>

                </tr>

                <!-- ===================== -->
                <!-- HERO -->
                <!-- ===================== -->

                <tr>

                    <td style="padding:45px 45px 20px;">

                        <h2 style="
                margin:0;
                color:#000000;
                font-size:30px;
                font-weight:800;
                ">

                            Bonjour 👋

                        </h2>

                        <p style="
                margin:18px 0 0;
                color:#5f5f5f;
                font-size:17px;
                line-height:32px;
                ">

                            Vous avez reçu un nouveau message depuis le formulaire
                            de contact du site
                            <strong style="color:#2777fc;">
                                elchat.io
                            </strong>.

                            <br><br>

                            Les informations du visiteur sont résumées ci-dessous.

                        </p>

                    </td>

                </tr>

                <!-- ===================== -->
                <!-- CARD -->
                <!-- ===================== -->

                <tr>

                    <td style="padding:0 45px 40px;">

                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            style="
                    border:1px solid #edf2f7;
                    border-radius:16px;
                    background:#ffffff;
                    ">

                            <tr>

                                <td
                                    style="
                            padding:28px;
                            border-bottom:1px solid #edf2f7;
                            ">

                                    <h3 style="
                            margin:0;
                            font-size:22px;
                            color:#000000;
                            font-weight:700;
                            ">

                                        Informations du contact

                                    </h3>

                                    <p style="
                            margin:10px 0 0;
                            color:#777;
                            font-size:15px;
                            line-height:28px;
                            ">

                                        Toutes les informations transmises
                                        par le visiteur.

                                    </p>

                                </td>

                            </tr>

                            <tr>

                                <td style="padding:28px;">
                                    <table
                                        width="100%"
                                        cellpadding="0"
                                        cellspacing="0"
                                        style="border-collapse:collapse;">

                                        <tr>

                                            <td width="170"
                                                style="
                                    padding:14px 0;
                                    font-weight:700;
                                    color:#000000;
                                    border-bottom:1px solid #edf2f7;
                                    ">

                                                Nom

                                            </td>

                                            <td
                                                style="
                                        padding:14px 0;
                                        color:#5f5f5f;
                                        border-bottom:1px solid #edf2f7;
                                        ">

                                                {{ $data['name'] }}

                                            </td>

                                        </tr>

                                        <tr>

                                            <td
                                                style="
                                        padding:14px 0;
                                        font-weight:700;
                                        color:#000000;
                                        border-bottom:1px solid #edf2f7;
                                        ">

                                                Email

                                            </td>

                                            <td
                                                style="
                                        padding:14px 0;
                                        color:#2777fc;
                                        border-bottom:1px solid #edf2f7;
                                        ">

                                                <a href="mailto:{{ $data['email'] }}"
                                                   style="
                                       color:#2777fc;
                                       text-decoration:none;
                                       ">

                                                    {{ $data['email'] }}

                                                </a>

                                            </td>

                                        </tr>

                                        <tr>

                                            <td
                                                style="
                                        padding:14px 0;
                                        font-weight:700;
                                        color:#000000;
                                        border-bottom:1px solid #edf2f7;
                                        ">

                                                Téléphone

                                            </td>

                                            <td
                                                style="
                                        padding:14px 0;
                                        color:#5f5f5f;
                                        border-bottom:1px solid #edf2f7;
                                        ">

                                                {{ $data['phone'] ?: 'Non renseigné' }}

                                            </td>

                                        </tr>

                                    </table>

                                </td>

                            </tr>

                        </table>

                    </td>

                </tr>

                <!-- MESSAGE -->

                <tr>

                    <td style="padding:0 45px 35px;">

                        <div
                            style="
                    background:#f8fbff;
                    border-left:5px solid #2777fc;
                    border-radius:12px;
                    padding:28px;
                    ">

                            <div
                                style="
                        font-size:22px;
                        font-weight:700;
                        color:#000000;
                        margin-bottom:18px;
                        ">

                                Message

                            </div>

                            <div
                                style="
                        color:#5f5f5f;
                        font-size:16px;
                        line-height:32px;
                        white-space:pre-line;
                        ">

                                {{ $data['message'] }}

                            </div>

                        </div>

                    </td>

                </tr>

                <!-- INFOS TECHNIQUES -->

                <tr>

                    <td style="padding:0 45px 40px;">

                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            style="
                    background:#fafafa;
                    border:1px solid #eeeeee;
                    border-radius:12px;
                    ">

                            <tr>

                                <td style="padding:22px;">

                                    <div
                                        style="
                                font-size:18px;
                                font-weight:700;
                                color:#000000;
                                margin-bottom:18px;
                                ">

                                        Informations techniques

                                    </div>

                                    <table
                                        width="100%"
                                        cellpadding="0"
                                        cellspacing="0">

                                        <tr>

                                            <td style="padding:8px 0;font-weight:600;">
                                                Date
                                            </td>

                                            <td style="padding:8px 0;color:#5f5f5f;">
                                                {{ $data['date']->format('d/m/Y H:i:s') }}
                                            </td>

                                        </tr>

                                        <tr>

                                            <td style="padding:8px 0;font-weight:600;">
                                                Adresse IP
                                            </td>

                                            <td style="padding:8px 0;color:#5f5f5f;">
                                                {{ $data['ip'] }}
                                            </td>

                                        </tr>

                                        <tr>

                                            <td style="padding:8px 0;font-weight:600;">
                                                Navigateur
                                            </td>

                                            <td style="padding:8px 0;color:#5f5f5f;word-break:break-word;">
                                                {{ $data['user_agent'] }}
                                            </td>

                                        </tr>

                                    </table>

                                </td>

                            </tr>

                        </table>

                    </td>

                </tr>

                <!-- BOUTON -->

                <tr>

                    <td align="center" style="padding:0 45px 50px;">

                        <a href="mailto:{{ $data['email'] }}"
                           style="
               display:inline-block;
               background:#2777fc;
               color:#ffffff;
               text-decoration:none;
               padding:18px 42px;
               border-radius:10px;
               font-size:16px;
               font-weight:700;
               ">

                            Répondre au contact

                        </a>

                    </td>

                </tr>

                <!-- FOOTER -->

                <tr>

                    <td
                        align="center"
                        style="
                background:#000000;
                padding:45px 30px;
                ">

                        <div
                            style="
                    color:#ffffff;
                    font-size:26px;
                    font-weight:800;
                    margin-bottom:10px;
                    ">

                            ELChat

                        </div>

                        <div
                            style="
                    color:#bebebe;
                    font-size:15px;
                    line-height:28px;
                    max-width:500px;
                    margin:auto;
                    ">

                            Plateforme intelligente de communication omnicanale
                            permettant aux entreprises de centraliser leurs échanges,
                            automatiser leurs réponses et offrir une expérience client
                            moderne.

                        </div>

                        <div
                            style="
                    margin-top:25px;
                    color:#8d8d8d;
                    font-size:13px;
                    line-height:24px;
                    ">

                            © {{ date('Y') }} ELChat. Tous droits réservés.

                            <br>

                            Cet email a été généré automatiquement depuis le formulaire
                            de contact du site.

                        </div>

                    </td>

                </tr>

            </table>

        </td>

    </tr>

</table>

</body>

</html>
