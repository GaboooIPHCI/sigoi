<?php
declare(strict_types=1);

$ultimaActualizacion = '31 de agosto de 2026';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Política de Privacidad | S.I.G.O.I. - IPHCI</title>
    <meta name="description" content="Política de privacidad aplicable al uso de S.I.G.O.I. y a las integraciones de mensajería de IPHCI con Meta, Instagram y WhatsApp.">
    <style>
        :root{
            --bg:#f5f7fb;
            --card:#ffffff;
            --text:#172033;
            --muted:#667085;
            --line:#e6eaf0;
            --accent:#5f2eea;
            --accent-soft:#f0ebff;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            background:var(--bg);
            color:var(--text);
            font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            line-height:1.65;
        }
        .wrap{
            width:min(980px,calc(100% - 32px));
            margin:40px auto;
        }
        .hero,.card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:18px;
            box-shadow:0 8px 28px rgba(16,24,40,.05);
        }
        .hero{padding:34px;margin-bottom:18px}
        .badge{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            background:var(--accent-soft);
            color:var(--accent);
            font-size:12px;
            font-weight:800;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        h1{font-size:34px;line-height:1.15;margin:14px 0 10px}
        h2{font-size:21px;margin:0 0 10px}
        p{margin:0 0 12px}
        .muted{color:var(--muted)}
        .card{padding:26px;margin-bottom:14px}
        ul{margin:8px 0 0;padding-left:22px}
        li{margin:5px 0}
        a{color:var(--accent);text-decoration:none}
        a:hover{text-decoration:underline}
        .notice{
            border-left:4px solid var(--accent);
            background:#faf8ff;
            padding:14px 16px;
            border-radius:10px;
            margin-top:12px;
        }
        footer{
            color:var(--muted);
            font-size:13px;
            text-align:center;
            padding:12px 0 34px;
        }
        @media (max-width:640px){
            .wrap{width:min(100% - 20px,980px);margin:18px auto}
            .hero,.card{padding:21px;border-radius:14px}
            h1{font-size:28px}
        }
    </style>
</head>
<body>
<main class="wrap">

    <section class="hero">
        <span class="badge">Privacidad y protección de datos</span>
        <h1>Política de Privacidad</h1>
        <p>
            Esta Política de Privacidad describe cómo <strong>IPHCI</strong>, a través de su sistema
            <strong>S.I.G.O.I. (Sistema Integral de Gestión Operativa e Información)</strong>,
            trata la información recibida mediante sus canales digitales y sus integraciones con
            plataformas de Meta, incluyendo Instagram y WhatsApp.
        </p>
        <p class="muted">Última actualización: <?= htmlspecialchars($ultimaActualizacion, ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <section class="card">
        <h2>1. Responsable del tratamiento</h2>
        <p>
            El responsable del tratamiento de la información es <strong>IPHCI</strong>, para los fines
            relacionados con atención al usuario, gestión de consultas, coordinación de citas,
            seguimiento de solicitudes y operación interna de S.I.G.O.I.
        </p>
        <p>
            Sitio web oficial:
            <a href="https://www.iphci.com.pe/" target="_blank" rel="noopener noreferrer">www.iphci.com.pe</a>.
        </p>
    </section>

    <section class="card">
        <h2>2. Información que podemos recibir</h2>
        <p>Dependiendo del canal utilizado y de la información que el usuario decida proporcionar, S.I.G.O.I. puede tratar:</p>
        <ul>
            <li>Identificador de usuario de Instagram, WhatsApp u otros canales conectados.</li>
            <li>Nombre de perfil, nombre visible o nombre proporcionado voluntariamente.</li>
            <li>Número de teléfono cuando el canal utilizado lo requiera o lo facilite.</li>
            <li>Contenido de mensajes enviados y recibidos.</li>
            <li>Imágenes, documentos, audios u otros archivos compartidos durante la conversación.</li>
            <li>Fecha y hora de los mensajes, estados de entrega, lectura y datos técnicos necesarios para la atención.</li>
            <li>Información relacionada con la consulta, servicio o cita que el usuario comunique voluntariamente.</li>
            <li>Notas internas de atención necesarias para dar continuidad a una conversación o solicitud.</li>
        </ul>
        <div class="notice">
            El usuario decide qué información comparte en una conversación. Se recomienda no enviar información
            que no sea necesaria para gestionar su consulta o atención.
        </div>
    </section>

    <section class="card">
        <h2>3. Finalidades del uso de la información</h2>
        <p>La información se utiliza únicamente para fines vinculados con la operación y atención de IPHCI, entre ellos:</p>
        <ul>
            <li>Responder consultas recibidas por canales digitales.</li>
            <li>Gestionar solicitudes de información y coordinación de citas.</li>
            <li>Dar seguimiento a conversaciones iniciadas por el usuario.</li>
            <li>Permitir que personal autorizado de IPHCI atienda mensajes desde S.I.G.O.I.</li>
            <li>Automatizar respuestas operativas cuando corresponda.</li>
            <li>Mantener continuidad e historial de atención.</li>
            <li>Generar métricas internas sobre tiempos de respuesta, volumen de atención y desempeño operativo.</li>
            <li>Prevenir fallos, abusos, accesos no autorizados y problemas técnicos.</li>
        </ul>
    </section>

    <section class="card">
        <h2>4. Integraciones con Meta, Instagram y WhatsApp</h2>
        <p>
            S.I.G.O.I. puede utilizar APIs, webhooks y servicios oficiales de Meta o proveedores autorizados para
            recibir y responder mensajes de Instagram y WhatsApp.
        </p>
        <p>
            La información obtenida mediante estas integraciones se utiliza exclusivamente para gestionar las
            conversaciones y funciones descritas en esta política. S.I.G.O.I. no vende los datos personales de los usuarios
            ni los utiliza para comercializarlos a terceros.
        </p>
    </section>

    <section class="card">
        <h2>5. Acceso interno y confidencialidad</h2>
        <p>
            El acceso a S.I.G.O.I. está limitado al personal autorizado según sus permisos y funciones.
            Los usuarios internos pueden contar con permisos diferenciados para visualizar conversaciones,
            responder mensajes, gestionar automatizaciones, administrar plantillas o consultar analítica.
        </p>
    </section>

    <section class="card">
        <h2>6. Conservación de la información</h2>
        <p>
            La información se conserva durante el tiempo necesario para mantener la continuidad de la atención,
            cumplir finalidades operativas, resolver solicitudes, atender obligaciones aplicables y mantener
            registros razonables de seguridad y funcionamiento.
        </p>
        <p>
            Cuando la información deje de ser necesaria, podrá ser eliminada, anonimizada o restringida de acuerdo
            con las necesidades operativas y la legislación aplicable.
        </p>
    </section>

    <section class="card">
        <h2>7. Seguridad</h2>
        <p>
            IPHCI aplica medidas técnicas y organizativas razonables para proteger la información contra accesos
            no autorizados, pérdida, alteración, uso indebido o divulgación. Estas medidas incluyen controles de acceso,
            validación de integraciones, separación de credenciales privadas y registro de operaciones relevantes.
        </p>
    </section>

    <section class="card">
        <h2>8. Terceros y proveedores tecnológicos</h2>
        <p>
            Para prestar los servicios de mensajería y operación digital pueden intervenir proveedores tecnológicos,
            servicios de hosting y plataformas como Meta, Instagram, WhatsApp u otros servicios autorizados.
            Estos proveedores pueden tratar información en la medida necesaria para prestar sus servicios.
        </p>
    </section>

    <section class="card" id="eliminacion-datos">
        <h2>9. Solicitud de acceso, corrección o eliminación de datos</h2>
        <p>
            El usuario puede solicitar información sobre sus datos, pedir su corrección o solicitar su eliminación
            cuando corresponda.
        </p>
        <p>Para solicitar la eliminación de datos vinculados a una conversación de Instagram o WhatsApp:</p>
        <ul>
            <li>Contacte a IPHCI mediante sus canales oficiales publicados en <a href="https://www.iphci.com.pe/" target="_blank" rel="noopener noreferrer">www.iphci.com.pe</a>.</li>
            <li>Indique claramente que desea realizar una <strong>solicitud de eliminación de datos</strong>.</li>
            <li>Indique el canal utilizado (Instagram o WhatsApp) y el usuario o número asociado a la conversación.</li>
            <li>IPHCI podrá solicitar información mínima adicional para validar la identidad del solicitante antes de ejecutar la eliminación.</li>
        </ul>
        <p>
            Una vez validada la solicitud, se procederá a revisar y eliminar o anonimizar la información que corresponda,
            salvo aquella cuya conservación sea necesaria por obligaciones legales, de seguridad o de atención previamente generada.
        </p>
    </section>

    <section class="card">
        <h2>10. Menores de edad</h2>
        <p>
            Los canales digitales de IPHCI no están diseñados para que menores de edad proporcionen información personal
            sin la participación o autorización de sus padres, tutores o responsables cuando corresponda.
        </p>
    </section>

    <section class="card">
        <h2>11. Cambios a esta política</h2>
        <p>
            Esta Política de Privacidad puede actualizarse cuando cambien las funciones de S.I.G.O.I.,
            las integraciones tecnológicas, los procesos internos o la normativa aplicable.
            La versión vigente será la publicada en esta página.
        </p>
    </section>

    <section class="card">
        <h2>12. Contacto</h2>
        <p>
            Para consultas relacionadas con privacidad o tratamiento de datos, el usuario puede comunicarse con IPHCI
            mediante los canales oficiales publicados en
            <a href="https://www.iphci.com.pe/" target="_blank" rel="noopener noreferrer">www.iphci.com.pe</a>.
        </p>
    </section>

    <footer>
        © <?= date('Y') ?> IPHCI · S.I.G.O.I. · Política de Privacidad
    </footer>

</main>
</body>
</html>
