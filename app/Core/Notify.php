<?php
class Notify
{
    public static function sendEmail(string $to, string $subject, string $body): array
    {
        $host = setting('smtp_host','smtp.gmail.com');
        $port = (int)setting('smtp_port','587');
        $enc  = setting('smtp_encryption','tls');
        $user = setting('smtp_username','');
        $pass = setting('smtp_password','');
        $from = setting('mail_from_email', $user);
        $name = setting('mail_from_name','Accounting Manager');
        if (!$to || !$host || !$user || !$pass || !$from) return ['ok'=>false,'response'=>'تنظیمات SMTP کامل نیست.'];
        $headers = [
            'From: =?UTF-8?B?'.base64_encode($name).'?= <'.$from.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        // Prefer authenticated SMTP. Fallback to mail() only if SMTP fails.
        try {
            $res = self::smtpSend($host, $port, $enc, $user, $pass, $from, $to, $subject, $body);
            if ($res['ok']) return $res;
        } catch (Throwable $e) { $res = ['ok'=>false,'response'=>$e->getMessage()]; }
        $ok = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n", $headers));
        return ['ok'=>$ok, 'response'=>$ok ? 'Sent by mail() fallback' : ($res['response'] ?? 'SMTP and mail() failed')];
    }

    private static function smtpSend(string $host, int $port, string $enc, string $username, string $password, string $from, string $to, string $subject, string $body): array
    {
        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
        if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($fp, 25);
        $read = function() use ($fp) { $data=''; while (($line=fgets($fp,515)) !== false) { $data.=$line; if (preg_match('/^\d{3} /',$line)) break; } return $data; };
        $cmd = function($c) use ($fp,$read) { fwrite($fp,$c."\r\n"); return $read(); };
        $read();
        $cmd('EHLO '.($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($enc === 'tls') { $r = $cmd('STARTTLS'); if (!str_starts_with($r,'220')) throw new RuntimeException('STARTTLS failed: '.$r); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd('EHLO '.($_SERVER['SERVER_NAME'] ?? 'localhost')); }
        $r=$cmd('AUTH LOGIN'); if (!str_starts_with($r,'334')) throw new RuntimeException('SMTP AUTH failed: '.$r);
        $cmd(base64_encode($username)); $cmd(base64_encode($password));
        $cmd('MAIL FROM:<'.$from.'>'); $cmd('RCPT TO:<'.$to.'>'); $cmd('DATA');
        $msg = "From: <{$from}>\r\nTo: <{$to}>\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body."\r\n.";
        $r = $cmd($msg);
        $cmd('QUIT'); fclose($fp);
        return ['ok'=>str_starts_with($r,'250'), 'response'=>$r];
    }

    public static function sendSms(string $receptors, string $message): array
    {
        $apiKey = setting('ghasedak_api_key','');
        $line = setting('ghasedak_line_number','');
        if (!$apiKey || !$receptors) return ['ok'=>false,'response'=>'تنظیمات قاصدک کامل نیست.'];
        $phones = normalize_phone_list($receptors);
        $allOk = true; $responses = [];
        foreach ($phones as $phone) {
            $payload = json_encode([
                'sendDate' => null,
                'lineNumber' => $line,
                'receptor' => $phone,
                'message' => $message,
                'clientReferenceId' => 'acct-'.date('YmdHis').'-'.random_int(100,999),
                'udh' => false,
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init('https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json','ApiKey: '.$apiKey], CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>25]);
            $response = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $ok = ($err === '' && $code >= 200 && $code < 300 && str_contains((string)$response, 'IsSuccess'));
            $allOk = $allOk && $ok; $responses[] = $phone.': '.($err ?: $response);
        }
        return ['ok'=>$allOk, 'response'=>implode("\n", $responses)];
    }

    public static function sendDueReminders(): array
    {
        $today = date('Y-m-d');
        $emailTo = setting('notifications_email_to','');
        $smsTo = setting('notifications_sms_to','');
        $sent = ['email'=>0,'sms'=>0,'items'=>0,'errors'=>[]];
        $queries = [];
        $queries[] = [
            'sql' => "SELECT 'برنامه ماهانه' source, m.id, c.name company_name, m.work_type title, m.legal_deadline due_date, m.status FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.status NOT IN ('انجام شده','بسته شده','لغو شده') AND m.legal_deadline IS NOT NULL AND m.legal_deadline <= DATE_ADD(?, INTERVAL 7 DAY) ORDER BY m.legal_deadline ASC LIMIT 120",
            'params' => [$today]
        ];
        $queries[] = [
            'sql' => "SELECT CONCAT('ماژول ', module_key) source, r.id, c.name company_name, r.title, r.due_date, r.status FROM module_records r LEFT JOIN companies c ON c.id=r.company_id WHERE r.status NOT IN ('انجام شده','بسته شده','لغو شده') AND r.due_date IS NOT NULL AND r.due_date <= DATE_ADD(?, INTERVAL 7 DAY) ORDER BY r.due_date ASC LIMIT 120",
            'params' => [$today]
        ];
        foreach ($queries as $query) {
            $st = pdo()->prepare($query['sql']); $st->execute($query['params']);
            foreach ($st->fetchAll() as $item) {
                $sent['items']++;
                $status = due_status($item['due_date'],$item['status']);
                $msg = "یادآوری سررسید حسابداری\nبخش: {$item['source']}\nشرکت: {$item['company_name']}\nکار: {$item['title']}\nسررسید: ".Jalali::fromGregorian($item['due_date'])."\nوضعیت: {$status}";
                foreach (['email'=>$emailTo,'sms'=>$smsTo] as $channel=>$rec) {
                    if (!$rec) continue;
                    $fingerprint = sha1($item['source'].'-'.$item['id'].'-'.$channel.'-'.date('Y-m-d'));
                    $chk = pdo()->prepare("SELECT COUNT(*) FROM notification_logs WHERE response LIKE ? AND channel=? AND DATE(created_at)=CURDATE()");
                    $chk->execute(['%'.$fingerprint.'%',$channel]); if ($chk->fetchColumn() > 0) continue;
                    $res = $channel === 'email' ? self::sendEmail($rec, 'یادآوری سررسید حسابداری', $msg) : self::sendSms($rec, $msg);
                    $response = $res['response']."\nfingerprint:".$fingerprint;
                    $lg = pdo()->prepare("INSERT INTO notification_logs (task_id,channel,recipient,message,status,response,created_at) VALUES (NULL,?,?,?,?,?,NOW())");
                    $lg->execute([$channel,$rec,$msg,$res['ok']?'sent':'failed',$response]);
                    if ($res['ok']) $sent[$channel]++; else $sent['errors'][] = $channel.': '.$res['response'];
                }
            }
        }
        return $sent;
    }
}