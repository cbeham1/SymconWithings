<?php

declare(strict_types=1);

/**
 * Withings Scale
 *
 * One instance per Withings account (person). Reads the body measurements of all
 * Withings scales through the Withings Health API (OAuth 2.0 with an own developer
 * app). Variables are created only for values that are actually measured.
 * Optional: status tile (HTML box) with weight trend and push notifications of
 * Withings (new measurements arrive within seconds instead of the next poll).
 */
class WithingsScale extends IPSModule
{
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const CONNECT_CONTROL = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';

    private const URL_AUTHORIZE = 'https://account.withings.com/oauth2_user/authorize2';
    private const URL_OAUTH = 'https://wbsapi.withings.net/v2/oauth2';
    private const URL_MEASURE = 'https://wbsapi.withings.net/measure';
    private const URL_NOTIFY = 'https://wbsapi.withings.net/notify';

    private const STATUS_NO_CLIENT = 201;
    private const STATUS_NOT_AUTHORIZED = 202;
    private const STATUS_REQUEST_FAILED = 203;

    private const FIRST_UPDATE_DELAY = 5;
    private const NOTIFY_UPDATE_DELAY = 3;
    private const MAX_HISTORY = 2000;

    // Withings measure type => [ident, name, variable type, profile, position]
    private const TYPES = [
        1   => ['Weight', 'Weight', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 10],
        6   => ['FatRatio', 'Body fat', VARIABLETYPE_FLOAT, 'WTH.Percent', 12],
        8   => ['FatMass', 'Fat mass', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 13],
        5   => ['FatFreeMass', 'Fat free mass', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 14],
        76  => ['MuscleMass', 'Muscle mass', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 15],
        77  => ['Hydration', 'Body water', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 16],
        88  => ['BoneMass', 'Bone mass', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 18],
        170 => ['VisceralFat', 'Visceral fat', VARIABLETYPE_FLOAT, 'WTH.Index', 19],
        226 => ['BMR', 'Basal metabolic rate', VARIABLETYPE_INTEGER, 'WTH.Kcal', 20],
        227 => ['MetabolicAge', 'Metabolic age', VARIABLETYPE_INTEGER, 'WTH.Years', 21],
        11  => ['HeartRate', 'Heart rate', VARIABLETYPE_INTEGER, 'WTH.BPM', 22],
        91  => ['PulseWaveVelocity', 'Pulse wave velocity', VARIABLETYPE_FLOAT, 'WTH.Speed', 23],
        155 => ['VascularAge', 'Vascular age', VARIABLETYPE_INTEGER, 'WTH.Years', 24],
        167 => ['NerveHealth', 'Nerve health score', VARIABLETYPE_INTEGER, 'WTH.Score', 25],
        168 => ['ExtracellularWater', 'Extracellular water', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 26],
        169 => ['IntracellularWater', 'Intracellular water', VARIABLETYPE_FLOAT, 'WTH.Kilogram', 27],
        4   => ['Height', 'Height', VARIABLETYPE_FLOAT, 'WTH.Meter', 28],
    ];

    // Segmental values (Body Scan): measure type => [ident, name]
    private const SEGMENT_TYPES = [
        173 => ['FatFreeMass', 'Fat free mass'],
        174 => ['FatMass', 'Fat mass'],
        175 => ['MuscleMass', 'Muscle mass'],
    ];

    // Position of a segmental value => [ident, name]
    private const SEGMENTS = [
        2  => ['RightArm', 'right arm'],
        3  => ['LeftArm', 'left arm'],
        10 => ['LeftLeg', 'left leg'],
        11 => ['RightLeg', 'right leg'],
        12 => ['Trunk', 'trunk'],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RedirectURI', '');
        $this->RegisterPropertyInteger('UpdateInterval', 30);
        $this->RegisterPropertyBoolean('UseNotify', false);
        $this->RegisterPropertyBoolean('IgnoreAmbiguous', true);
        $this->RegisterPropertyBoolean('CreateSegments', true);
        $this->RegisterPropertyFloat('Height', 0);
        $this->RegisterPropertyBoolean('ShowTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyInteger('TileDays', 30);
        $this->RegisterPropertyFloat('TileGoal', 0);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('UserID', '');
        $this->RegisterAttributeString('OAuthState', '');
        $this->RegisterAttributeString('HookToken', '');
        $this->RegisterAttributeString('NotifyURL', '');
        $this->RegisterAttributeInteger('LastUpdate', 0);
        $this->RegisterAttributeFloat('Height', 0);
        $this->RegisterAttributeString('Latest', '{}');
        $this->RegisterAttributeString('History', '[]');

        $this->RegisterTimer('Update', 0, 'WTH_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->MaintainVariable('Tile', $this->Translate('Status tile'), VARIABLETYPE_STRING, '~HTMLBox', 0, $this->ReadPropertyBoolean('ShowTile'));
        $this->MaintainVariable('LastMeasurement', $this->Translate('Last measurement'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 2, true);

        if ($this->ReadAttributeString('HookToken') === '') {
            $this->WriteAttributeString('HookToken', bin2hex(random_bytes(16)));
        }
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook();
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }

        if ($this->ReadPropertyString('ClientID') === '' || $this->ReadPropertyString('ClientSecret') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NO_CLIENT);
            return;
        }
        if ($this->ReadAttributeString('RefreshToken') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NOT_AUTHORIZED);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateNotify();
        }
        $this->SetTimerInterval('Update', self::FIRST_UPDATE_DELAY * 1000);
    }

    public function Destroy(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UnregisterHook();
        }
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->RegisterHook();
            if ($this->GetStatus() === IS_ACTIVE) {
                $this->UpdateNotify();
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $redirect = $this->GetRedirectURI();
        $authorized = $this->ReadAttributeString('RefreshToken') !== '';
        $info = [
            ['type' => 'Label', 'caption' => $authorized
                ? sprintf($this->Translate('Authorized (Withings user %s)'), $this->ReadAttributeString('UserID'))
                : $this->Translate('Not authorized yet: save, then click "Authorize at Withings"')],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'CallbackURL',
                'width'   => '700px',
                'caption' => $this->Translate('Callback URL to enter in the Withings developer app'),
                'value'   => $redirect !== '' ? $redirect : $this->Translate('No Symcon Connect URL found, enter an own redirect URL'),
            ],
        ];
        array_splice($form['actions'], 0, 0, $info);
        return (string) json_encode($form);
    }

    /** Reads new measurements from Withings and updates the variables and the tile. */
    public function Update(): bool
    {
        $interval = max(0, $this->ReadPropertyInteger('UpdateInterval')) * 60000;
        $this->SetTimerInterval('Update', $interval);

        $token = $this->GetAccessToken();
        if ($token === null) {
            return false;
        }

        // Height for the BMI: setting > measurement, read once from the whole history
        if ($this->ReadPropertyFloat('Height') <= 0 && $this->ReadAttributeFloat('Height') <= 0) {
            $body = $this->Api(self::URL_MEASURE, ['action' => 'getmeas', 'meastype' => 4, 'category' => 1], $token);
            if ($body !== null) {
                $this->ProcessGroups($body['measuregrps'] ?? []);
            }
        }

        $params = [
            'action'    => 'getmeas',
            'meastypes' => implode(',', array_merge(array_keys(self::TYPES), array_keys(self::SEGMENT_TYPES))),
            'category'  => 1,
        ];
        $last = $this->ReadAttributeInteger('LastUpdate');
        if ($last > 0) {
            $params['lastupdate'] = $last - 300;
        } else {
            $params['startdate'] = time() - max(30, $this->ReadPropertyInteger('TileDays')) * 86400;
            $params['enddate'] = time();
        }

        $groups = [];
        $updateTime = 0;
        for ($page = 0; $page < 20; $page++) {
            $body = $this->Api(self::URL_MEASURE, $params, $token);
            if ($body === null) {
                return false;
            }
            $groups = array_merge($groups, $body['measuregrps'] ?? []);
            $updateTime = max($updateTime, (int) ($body['updatetime'] ?? 0));
            if (empty($body['more'])) {
                break;
            }
            $params['offset'] = (int) ($body['offset'] ?? 0);
        }
        $this->SendDebug('Update', count($groups) . ' measure groups', 0);

        $this->ProcessGroups($groups);
        $this->WriteAttributeInteger('LastUpdate', $updateTime > 0 ? $updateTime : time());
        $this->WriteVariables();
        return true;
    }

    /** URL of the Withings authorization page (opened by the button in the configuration form). */
    public function GetAuthorizationURL(): string
    {
        $state = bin2hex(random_bytes(8));
        $this->WriteAttributeString('OAuthState', $state);
        return self::URL_AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->ReadPropertyString('ClientID'),
            'scope'         => 'user.metrics',
            'redirect_uri'  => $this->GetRedirectURI(),
            'state'         => $state,
        ]);
    }

    /** Completes the authorization with the code (or the whole URL) Withings redirected to. Withings codes are valid for 30 seconds only. */
    public function CompleteAuthorization(string $Code): bool
    {
        $Code = trim($Code);
        if (strpos($Code, 'code=') !== false) {
            parse_str((string) parse_url($Code, PHP_URL_QUERY), $query);
            $Code = (string) ($query['code'] ?? '');
        }
        if ($Code === '') {
            return false;
        }
        $body = $this->Api(self::URL_OAUTH, [
            'action'        => 'requesttoken',
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->ReadPropertyString('ClientID'),
            'client_secret' => $this->ReadPropertyString('ClientSecret'),
            'code'          => $Code,
            'redirect_uri'  => $this->GetRedirectURI(),
        ], null);
        if ($body === null || !$this->StoreTokens($body)) {
            return false;
        }
        $this->WriteAttributeString('UserID', (string) ($body['userid'] ?? ''));
        $this->WriteAttributeInteger('LastUpdate', 0);
        $this->WriteAttributeString('NotifyURL', '');
        $this->SetStatus(IS_ACTIVE);
        $this->UpdateNotify();
        $this->SetTimerInterval('Update', self::FIRST_UPDATE_DELAY * 1000);
        return true;
    }

    /** Forgets the stored measurements and reads the last days again on the next update. */
    public function Reload(): bool
    {
        $this->WriteAttributeInteger('LastUpdate', 0);
        $this->WriteAttributeString('Latest', '{}');
        $this->WriteAttributeString('History', '[]');
        return $this->Update();
    }

    /** WebHook: OAuth callback (code/state) and push notifications of Withings (token n). */
    protected function ProcessHookData()
    {
        if (isset($_GET['code'], $_GET['state'])) {
            $ok = $this->ReadAttributeString('OAuthState') !== '' && hash_equals($this->ReadAttributeString('OAuthState'), (string) $_GET['state'])
                && $this->CompleteAuthorization((string) $_GET['code']);
            $this->WriteAttributeString('OAuthState', '');
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2em"><h2>Withings</h2><p>'
                . htmlspecialchars($this->Translate($ok ? 'Authorization successful. You can close this window.' : 'Authorization failed, see the messages of Symcon.'), ENT_QUOTES, 'UTF-8')
                . '</p></body></html>';
            return;
        }
        if (isset($_GET['n']) && hash_equals($this->ReadAttributeString('HookToken'), (string) $_GET['n'])) {
            // Withings checks the URL with an empty request and later posts userid, startdate, enddate, appli
            if (isset($_POST['appli'])) {
                $this->SendDebug('Notify', json_encode($_POST), 0);
                $this->SetTimerInterval('Update', self::NOTIFY_UPDATE_DELAY * 1000);
            }
            echo 'OK';
            return;
        }
        if ($_GET === [] && $_POST === []) {
            // Reachability test of the Withings developer dashboard (plain GET/HEAD): answer 200 without any data
            echo 'OK';
            return;
        }
        http_response_code(404);
        echo 'Not found';
    }

    /** Valid access token (refreshed if necessary) or null. */
    private function GetAccessToken(): ?string
    {
        if ($this->ReadAttributeString('RefreshToken') === '') {
            $this->SetStatus(self::STATUS_NOT_AUTHORIZED);
            return null;
        }
        if ($this->ReadAttributeString('AccessToken') !== '' && $this->ReadAttributeInteger('TokenExpires') > time() + 120) {
            return $this->ReadAttributeString('AccessToken');
        }
        $body = $this->Api(self::URL_OAUTH, [
            'action'        => 'requesttoken',
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->ReadPropertyString('ClientID'),
            'client_secret' => $this->ReadPropertyString('ClientSecret'),
            'refresh_token' => $this->ReadAttributeString('RefreshToken'),
        ], null, $status);
        if ($body === null) {
            // 503 = invalid refresh token (revoked or already used): a new authorization is needed
            if ($status === 503) {
                $this->WriteAttributeString('RefreshToken', '');
                $this->WriteAttributeString('AccessToken', '');
                $this->LogMessage($this->Translate('The Withings authorization is no longer valid, please authorize again'), KL_ERROR);
                $this->SetStatus(self::STATUS_NOT_AUTHORIZED);
            }
            return null;
        }
        return $this->StoreTokens($body) ? $this->ReadAttributeString('AccessToken') : null;
    }

    private function StoreTokens(array $body): bool
    {
        if (empty($body['access_token']) || empty($body['refresh_token'])) {
            $this->SendDebug('Token', 'No token in the response', 0);
            return false;
        }
        $this->WriteAttributeString('AccessToken', (string) $body['access_token']);
        $this->WriteAttributeString('RefreshToken', (string) $body['refresh_token']);
        $this->WriteAttributeInteger('TokenExpires', time() + (int) ($body['expires_in'] ?? 10800));
        $this->SendDebug('Token', 'Token stored, valid for ' . (int) ($body['expires_in'] ?? 0) . ' s', 0);
        return true;
    }

    /** POST to the Withings API; returns the body on status 0, otherwise null (status in $status). Tokens are never written to the debug output. */
    private function Api(string $url, array $params, ?string $token, ?int &$status = null): ?array
    {
        $status = -1;
        $action = (string) ($params['action'] ?? '');
        $curl = curl_init($url);
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data)) {
            $this->Fail($action, 'HTTP ' . $http . ' ' . $error);
            return null;
        }
        $status = (int) ($data['status'] ?? -1);
        if ($status !== 0) {
            if ($status === 401 && $token !== null) {
                // Access token rejected: refresh on the next request
                $this->WriteAttributeInteger('TokenExpires', 0);
            }
            $this->Fail($action, 'Withings status ' . $status . ' ' . (string) ($data['error'] ?? ''));
            return null;
        }
        if ($this->GetStatus() === self::STATUS_REQUEST_FAILED) {
            $this->SetStatus(IS_ACTIVE);
        }
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];
        if ($action !== 'requesttoken') {
            $this->SendDebug($action, (string) json_encode($body), 0);
        }
        return $body;
    }

    private function Fail(string $action, string $message): void
    {
        $this->SendDebug($action, $message, 0);
        if ($this->GetStatus() === IS_ACTIVE) {
            $this->LogMessage($action . ': ' . $message, KL_WARNING);
            $this->SetStatus(self::STATUS_REQUEST_FAILED);
        }
    }

    /** Takes the measure groups into the latest values and the weight history. */
    private function ProcessGroups(array $groups): void
    {
        usort($groups, static fn (array $a, array $b): int => ((int) $a['date']) <=> ((int) $b['date']));
        $latest = json_decode($this->ReadAttributeString('Latest'), true) ?: [];
        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $ignoreAmbiguous = $this->ReadPropertyBoolean('IgnoreAmbiguous');

        foreach ($groups as $group) {
            // attrib 1 = measured by a device but possibly of another person (e.g. guest on the scale)
            if ((int) ($group['category'] ?? 1) !== 1 || ($ignoreAmbiguous && (int) ($group['attrib'] ?? 0) === 1)) {
                continue;
            }
            $date = (int) $group['date'];
            $values = [];
            foreach ($group['measures'] ?? [] as $measure) {
                $type = (int) $measure['type'];
                $value = (float) $measure['value'] * (10 ** (int) $measure['unit']);
                if (isset(self::TYPES[$type])) {
                    $values[self::TYPES[$type][0]] = $value;
                } elseif (isset(self::SEGMENT_TYPES[$type], self::SEGMENTS[(int) ($measure['position'] ?? -1)])) {
                    $values[self::SEGMENT_TYPES[$type][0] . self::SEGMENTS[(int) $measure['position']][0]] = $value;
                }
            }
            if (isset($values['Height'])) {
                $this->WriteAttributeFloat('Height', $values['Height']);
            }
            $height = $this->GetHeight();
            if (isset($values['Weight']) && $height > 0) {
                $values['BMI'] = $values['Weight'] / ($height * $height);
            }
            if (isset($values['Weight'], $values['Hydration']) && $values['Weight'] > 0) {
                $values['HydrationRatio'] = $values['Hydration'] / $values['Weight'] * 100;
            }
            foreach ($values as $ident => $value) {
                if (!isset($latest[$ident]) || $latest[$ident][0] <= $date) {
                    $latest[$ident] = [$date, $value];
                }
            }
            if (isset($values['Weight'])) {
                $history[(string) $date] = [$date, round($values['Weight'], 2), isset($values['FatRatio']) ? round($values['FatRatio'], 1) : null];
            }
        }

        // History: one entry per measurement time, sorted, limited
        $history = array_values($history);
        usort($history, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $history = array_slice($history, -self::MAX_HISTORY);
        $this->WriteAttributeString('Latest', (string) json_encode($latest));
        $this->WriteAttributeString('History', (string) json_encode($history));
    }

    /** Creates the variables of the measured values and writes them; renders the tile. */
    private function WriteVariables(): void
    {
        $latest = json_decode($this->ReadAttributeString('Latest'), true) ?: [];
        $definitions = $this->VariableDefinitions();
        $lastMeasurement = 0;
        foreach ($latest as $ident => [$date, $value]) {
            if (!isset($definitions[$ident])) {
                continue;
            }
            [$name, $type, $profile, $position] = $definitions[$ident];
            $this->MaintainVariable($ident, $name, $type, $profile, $position, true);
            $this->SetValueIfChanged($ident, $type === VARIABLETYPE_INTEGER ? (int) round($value) : round((float) $value, 3));
            if ($ident === 'Weight') {
                $lastMeasurement = max($lastMeasurement, (int) $date);
            }
        }
        if ($lastMeasurement === 0) {
            foreach ($latest as [$date]) {
                $lastMeasurement = max($lastMeasurement, (int) $date);
            }
        }
        if ($lastMeasurement > 0) {
            $this->SetValueIfChanged('LastMeasurement', $lastMeasurement);
        }
        if ($this->ReadPropertyBoolean('ShowTile')) {
            $this->SetValueIfChanged('Tile', $this->TileHTML($latest));
        }
    }

    /** ident => [name, type, profile, position] of all possible variables. */
    private function VariableDefinitions(): array
    {
        $definitions = [];
        foreach (self::TYPES as [$ident, $name, $type, $profile, $position]) {
            $definitions[$ident] = [$this->Translate($name), $type, $profile, $position];
        }
        $definitions['BMI'] = ['BMI', VARIABLETYPE_FLOAT, 'WTH.BMI', 11];
        $definitions['HydrationRatio'] = [$this->Translate('Body water ratio'), VARIABLETYPE_FLOAT, 'WTH.Percent', 17];
        if ($this->ReadPropertyBoolean('CreateSegments')) {
            $position = 40;
            foreach (self::SEGMENTS as [$segmentIdent, $segmentName]) {
                foreach (self::SEGMENT_TYPES as [$typeIdent, $typeName]) {
                    $definitions[$typeIdent . $segmentIdent] = [$this->Translate($typeName) . ' ' . $this->Translate($segmentName), VARIABLETYPE_FLOAT, 'WTH.Kilogram', $position++];
                }
            }
        }
        return $definitions;
    }

    private function GetHeight(): float
    {
        $height = $this->ReadPropertyFloat('Height');
        return $height > 0 ? $height / 100 : $this->ReadAttributeFloat('Height');
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            return;
        }
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    /** Status tile: weight with trend, BMI scale, body composition and weight chart; fills the whole tile (container query units). */
    private function TileHTML(array $latest): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $dec = $this->Translate('.');
        $num = static fn (float $value, int $digits): string => number_format($value, $digits, $dec, '');
        $value = static fn (string $ident): ?float => isset($latest[$ident]) ? (float) $latest[$ident][1] : null;
        $ok = '#2ecc71';
        $warn = '#f5a623';
        $bad = '#e74c3c';
        $info = '#4aa3ff';

        $weight = $value('Weight');
        $date = isset($latest['Weight']) ? (int) $latest['Weight'][0] : 0;
        $days = max(7, $this->ReadPropertyInteger('TileDays'));
        $goal = $this->ReadPropertyFloat('TileGoal');
        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $t0 = time() - $days * 86400;
        $points = array_values(array_filter($history, static fn (array $p): bool => $p[0] >= $t0));

        // Measurement time, older than 7 days = warning color
        if ($date <= 0) {
            $when = '–';
        } elseif (date('Ymd', $date) === date('Ymd')) {
            $when = $this->Translate('Today') . ' ' . date('H:i', $date);
        } elseif (date('Ymd', $date) === date('Ymd', strtotime('-1 day'))) {
            $when = $this->Translate('Yesterday') . ' ' . date('H:i', $date);
        } else {
            $when = date('d.m. H:i', $date);
        }
        $old = $date > 0 && $date < time() - 7 * 86400;
        $accent = $weight === null || $old ? $warn : $info;

        // Trend badge (since the last measurement); green = towards the goal (or down without goal)
        $badge = '';
        $count = count($history);
        if ($weight !== null && $count >= 2) {
            $diff = $weight - (float) $history[$count - 2][1];
            $good = $goal > 0 ? abs($weight - $goal) < abs((float) $history[$count - 2][1] - $goal) : $diff < 0;
            $bc = abs($diff) < 0.05 ? 'var(--sub)' : ($good ? $ok : $warn);
            $arrow = abs($diff) < 0.05 ? '→' : ($diff < 0 ? '↘' : '↗');
            $badge = '<span class="wb" style="color:' . $bc . ';border-color:' . $bc . '">' . $arrow . ' ' . $e($this->Signed($diff, $num)) . ' kg</span>';
        }
        $sub = [];
        if ($weight !== null && count($points) >= 2) {
            $sub[] = $this->Signed($weight - (float) $points[0][1], $num) . ' kg ' . sprintf($this->Translate('in %d days'), $days);
        }
        if ($weight !== null && $goal > 0) {
            $rest = $weight - $goal;
            $sub[] = abs($rest) < 0.05 ? $this->Translate('Goal reached') : sprintf($this->Translate('%s kg to goal'), $num(abs($rest), 1));
        }

        // BMI scale 15–35 with the WHO ranges
        $bmiHTML = '';
        $bmi = $value('BMI');
        if ($bmi !== null) {
            $categories = [[18.5, 'Underweight', $info], [25, 'Normal', $ok], [30, 'Overweight', $warn], [99, 'Obese', $bad]];
            foreach ($categories as [$limit, $caption, $color]) {
                if ($bmi < $limit) {
                    break;
                }
            }
            $pos = max(0, min(100, ($bmi - 15) / 20 * 100));
            $bmiHTML = '<div class="wbmi"><div class="wbt"><span>BMI <b>' . $e($num($bmi, 1)) . '</b></span><span style="color:' . $color . '">'
                . $e($this->Translate($caption)) . '</span></div>'
                . '<div class="wbs"><i style="left:' . round($pos, 1) . '%"></i></div></div>';
        }

        // Body composition
        $stats = [];
        $add = function (string $ident, string $caption, int $digits, string $unit, string $color) use (&$stats, $value, $num): void
        {
            $v = $value($ident);
            if ($v !== null) {
                $stats[] = [$this->Translate($caption), $num($v, $digits) . $unit, $color];
            }
        };
        $add('FatRatio', 'Body fat', 1, ' %', '#f5a623');
        $add('MuscleMass', 'Muscle', 1, ' kg', '#e74c3c');
        $add('HydrationRatio', 'Water', 1, ' %', '#4aa3ff');
        $add('BoneMass', 'Bones', 1, ' kg', '#b0b8c1');
        $add('VisceralFat', 'Visceral fat', 0, '', '#c38bff');
        $add('HeartRate', 'Pulse', 0, ' bpm', '#ff6b8b');
        $add('MetabolicAge', 'Metabolic age', 0, '', '#2ecc71');
        $grid = '';
        foreach (array_slice($stats, 0, 6) as [$caption, $text, $color]) {
            $grid .= '<div class="wv" style="border-color:' . $color . '"><span>' . $e($caption) . '</span><b>' . $e($text) . '</b></div>';
        }

        // Weight chart: area + line scale with the tile, points and labels stay round (non-scaling strokes)
        $chart = '';
        if (count($points) >= 1) {
            $weights = array_map(static fn (array $p): float => (float) $p[1], $points);
            $min = min($weights);
            $max = max($weights);
            $showGoal = $goal > 0 && $goal >= $min - 3 && $goal <= $max + 3;
            if ($showGoal) {
                $min = min($min, $goal);
                $max = max($max, $goal);
            }
            $pad = max(0.3, ($max - $min) * 0.15);
            $min -= $pad;
            $max += $pad;
            $x = static fn (int $t): float => round(max(0, min(1000, ($t - $t0) / ($days * 86400) * 1000)), 1);
            $y = static fn (float $w): float => round(100 - ($w - $min) / ($max - $min) * 100, 2);
            $line = '';
            $dots = '';
            foreach ($points as $p) {
                $line .= ($line === '' ? 'M' : 'L') . $x((int) $p[0]) . ' ' . $y((float) $p[1]);
                $dots .= 'M' . $x((int) $p[0]) . ' ' . $y((float) $p[1]) . 'h0';
            }
            $first = $points[0];
            $last = $points[count($points) - 1];
            $area = $line . 'L' . $x((int) $last[0]) . ' 100L' . $x((int) $first[0]) . ' 100Z';
            $goalLine = $showGoal
                ? '<path d="M0 ' . $y($goal) . 'H1000" stroke="' . $ok . '" stroke-width="1.5" stroke-dasharray="5 5" vector-effect="non-scaling-stroke"/>'
                : '';
            $grid3 = '';
            for ($i = 1; $i < 4; $i++) {
                $grid3 .= 'M0 ' . ($i * 25) . 'H1000';
            }
            $chart = '<div class="wc"><div class="wct"><span>' . $e(sprintf($this->Translate('Weight %d days'), $days)) . '</span>'
                . '<span>' . $e($num(min($weights), 1) . ' – ' . $num(max($weights), 1) . ' kg') . '</span></div>'
                . '<svg viewBox="0 0 1000 100" preserveAspectRatio="none"><defs><linearGradient id="wg" x1="0" y1="0" x2="0" y2="1">'
                . '<stop offset="0" stop-color="' . $info . '" stop-opacity=".45"/><stop offset="1" stop-color="' . $info . '" stop-opacity="0"/></linearGradient></defs>'
                . '<path d="' . $grid3 . '" stroke="var(--grid)" stroke-width="1" vector-effect="non-scaling-stroke"/>'
                . $goalLine
                . (count($points) >= 2 ? '<path d="' . $area . '" fill="url(#wg)"/>'
                    . '<path d="' . $line . '" fill="none" stroke="' . $info . '" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>' : '')
                . '<path d="' . $dots . '" stroke="' . $info . '" stroke-width="7" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
                . '<path d="M' . $x((int) $last[0]) . ' ' . $y((float) $last[1]) . 'h0" stroke="var(--bg)" stroke-width="4" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
                . '</svg><div class="wcl"><span>' . date('d.m.', $t0) . '</span>'
                . ($showGoal ? '<span style="color:' . $ok . '">- - ' . $e($this->Translate('Goal')) . ' ' . $e($num($goal, 1)) . ' kg</span>' : '')
                . '<span>' . $e($this->Translate('Today')) . '</span></div></div>';
        }

        // Scale icon
        $icon = '<svg class="wi" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="4"/><path d="M8.5 10a4.5 4.5 0 0 1 7 0"/><path d="M12 10.5l1.2-1.6"/></svg>';

        $dark = '--fg:#eef2f6;--name:#d3dae1;--sub:#97a3af;--box:rgba(255,255,255,.055);--grid:rgba(255,255,255,.07);--bg:#1d2329;--card:linear-gradient(160deg,#232a31,#1a1f25)';
        $light = '--fg:#1f2933;--name:#3e4a56;--sub:#66717d;--box:rgba(0,0,0,.045);--grid:rgba(0,0,0,.07);--bg:#ffffff;--card:linear-gradient(160deg,#ffffff,#f1f4f8)';
        $theme = $this->ReadPropertyInteger('TileTheme');
        $vars = '.ww{' . ($theme === 2 ? $light : $dark) . '}';
        if ($theme === 0) {
            $vars .= '@media (prefers-color-scheme: light){.ww{' . $light . '}}';
        }
        $css = '<style>'
            . 'html,body{margin:0;height:100%;background:transparent;font-family:"Segoe UI",Arial,sans-serif;overflow:hidden}'
            . '.ww{height:100%;min-height:260px;box-sizing:border-box;container-type:size;border:1px solid ' . $accent . '55;border-top:3px solid ' . $accent . ';border-radius:12px;background:var(--card);color:var(--fg);overflow:hidden}'
            . '.ww *{box-sizing:border-box}'
            . '.wx{height:100%;display:flex;flex-direction:column;gap:clamp(6px,2.2cqh,14px);padding:clamp(10px,3cqh,18px) clamp(12px,4cqw,20px)}'
            . '.wh{display:flex;justify-content:space-between;align-items:center;gap:8px}'
            . '.wh b{display:flex;align-items:center;gap:8px;font-size:clamp(15px,5cqw,19px);color:var(--name)}'
            . '.wi{width:clamp(20px,6cqw,26px);height:auto;fill:none;stroke:' . $accent . ';stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}'
            . '.wt{font-size:clamp(11px,3.4cqw,13px);color:' . ($old ? $warn : 'var(--sub)') . ';white-space:nowrap}'
            . '.wn{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}'
            . '.wn strong{font-size:clamp(34px,min(17cqw,13cqh),76px);font-weight:700;line-height:1;color:' . $accent . ';letter-spacing:-1px}'
            . '.wn strong small{font-size:.4em;margin-left:4px;font-weight:600;letter-spacing:0}'
            . '.wb{font-size:clamp(12px,3.8cqw,15px);font-weight:600;border:1px solid;border-radius:20px;padding:3px 10px;white-space:nowrap}'
            . '.wu{text-align:center;font-size:clamp(11px,3.4cqw,14px);color:var(--sub);margin-top:-4px}'
            . '.wbmi{display:flex;flex-direction:column;gap:5px}'
            . '.wbt{display:flex;justify-content:space-between;font-size:clamp(12px,3.6cqw,14px);color:var(--sub)}.wbt b{color:var(--fg)}.wbt span:last-child{font-weight:600}'
            . '.wbs{position:relative;height:8px;border-radius:4px;background:linear-gradient(90deg,' . $info . ' 0 17.5%,' . $ok . ' 17.5% 50%,' . $warn . ' 50% 75%,' . $bad . ' 75%);opacity:.9}'
            . '.wbs i{position:absolute;top:50%;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;background:var(--fg);border:3px solid var(--bg);box-shadow:0 0 0 1px var(--fg)}'
            . '.wg{display:grid;grid-template-columns:repeat(auto-fit,minmax(clamp(78px,28cqw,120px),1fr));gap:clamp(5px,1.6cqh,10px)}'
            . '.wv{background:var(--box);border-left:3px solid;border-radius:8px;padding:clamp(4px,1.4cqh,9px) 10px;display:flex;flex-direction:column;min-width:0}'
            . '.wv span{font-size:clamp(10px,3cqw,12px);color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.wv b{font-size:clamp(14px,4.6cqw,19px);white-space:nowrap}'
            . '.wc{flex:1;min-height:70px;display:flex;flex-direction:column;gap:4px}'
            . '.wc svg{flex:1;width:100%;min-height:40px;display:block;overflow:visible}'
            . '.wct,.wcl{display:flex;justify-content:space-between;font-size:clamp(10px,3cqw,12px);color:var(--sub)}'
            . $vars
            . '</style>';

        return $css . '<div class="ww"><div class="wx">'
            . '<div class="wh"><b>' . $icon . $e($this->GetPersonName()) . '</b><span class="wt">' . $e($when) . '</span></div>'
            . '<div class="wn"><strong>' . ($weight === null ? '–' : $e($num($weight, 1)) . '<small>kg</small>') . '</strong>' . $badge . '</div>'
            . ($sub !== [] ? '<div class="wu">' . $e(implode(' · ', $sub)) . '</div>' : '')
            . $bmiHTML
            . ($grid !== '' ? '<div class="wg">' . $grid . '</div>' : '')
            . $chart
            . '</div></div>';
    }

    private function Signed(float $value, callable $num): string
    {
        if (abs($value) < 0.05) {
            return '±0' . $this->Translate('.') . '0';
        }
        return ($value > 0 ? '+' : '−') . $num(abs($value), 1);
    }

    /** Name for the tile = instance name without a leading "Withings ". */
    private function GetPersonName(): string
    {
        return (string) preg_replace('/^Withings\s+/u', '', IPS_GetName($this->InstanceID));
    }

    /** Redirect URL: own setting or the Symcon Connect URL with the WebHook of this instance. */
    private function GetRedirectURI(): string
    {
        $own = trim($this->ReadPropertyString('RedirectURI'));
        if ($own !== '') {
            return $own;
        }
        $base = $this->GetConnectURL();
        return $base === '' ? '' : $base . $this->HookPath();
    }

    private function GetConnectURL(): string
    {
        $ids = IPS_GetInstanceListByModuleID(self::CONNECT_CONTROL);
        if ($ids === [] || !function_exists('CC_GetURL')) {
            return '';
        }
        return rtrim((string) @CC_GetURL($ids[0]), '/');
    }

    /** Subscribes or revokes the push notification of Withings for new measurements (needs Symcon Connect). */
    private function UpdateNotify(): void
    {
        $base = $this->GetConnectURL();
        $wanted = $this->ReadPropertyBoolean('UseNotify') && $base !== '' ? $base . $this->HookPath() . '?n=' . $this->ReadAttributeString('HookToken') : '';
        $current = $this->ReadAttributeString('NotifyURL');
        if ($wanted === $current) {
            return;
        }
        $token = $this->GetAccessToken();
        if ($token === null) {
            return;
        }
        if ($current !== '') {
            $this->Api(self::URL_NOTIFY, ['action' => 'revoke', 'callbackurl' => $current, 'appli' => 1], $token);
        }
        if ($wanted !== '' && $this->Api(self::URL_NOTIFY, ['action' => 'subscribe', 'callbackurl' => $wanted, 'appli' => 1, 'comment' => 'IP-Symcon'], $token) === null) {
            $wanted = '';
        }
        $this->WriteAttributeString('NotifyURL', $wanted);
    }

    private function HookPath(): string
    {
        return '/hook/Withings' . $this->InstanceID;
    }

    /** Registers the WebHook of this instance at the WebHook Control. */
    private function RegisterHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if ($ids === []) {
            $this->LogMessage($this->Translate('WebHook Control not found, the authorization is not possible'), KL_WARNING);
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $this->HookPath() && $hook['TargetID'] === $this->InstanceID) {
                return;
            }
        }
        $hooks = array_values(array_filter($hooks, fn (array $hook): bool => $hook['Hook'] !== $this->HookPath()));
        $hooks[] = ['Hook' => $this->HookPath(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private function UnregisterHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if ($ids === []) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        $rest = array_values(array_filter($hooks, fn (array $hook): bool => $hook['Hook'] !== $this->HookPath()));
        if (count($rest) !== count($hooks)) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($rest));
            IPS_ApplyChanges($ids[0]);
        }
    }

    private function RegisterProfiles(): void
    {
        $profiles = [
            'WTH.Kilogram' => [VARIABLETYPE_FLOAT, 1, ' kg', 'Gauge'],
            'WTH.Percent'  => [VARIABLETYPE_FLOAT, 1, ' %', 'Intensity'],
            'WTH.BMI'      => [VARIABLETYPE_FLOAT, 1, '', 'Gauge'],
            'WTH.Index'    => [VARIABLETYPE_FLOAT, 0, '', 'Gauge'],
            'WTH.Meter'    => [VARIABLETYPE_FLOAT, 2, ' m', 'Distance'],
            'WTH.Speed'    => [VARIABLETYPE_FLOAT, 1, ' m/s', 'Graph'],
            'WTH.Kcal'     => [VARIABLETYPE_INTEGER, 0, ' kcal', 'Flame'],
            'WTH.BPM'      => [VARIABLETYPE_INTEGER, 0, ' bpm', 'Heart'],
            'WTH.Years'    => [VARIABLETYPE_INTEGER, 0, ' ' . $this->Translate('years'), 'Clock'],
            'WTH.Score'    => [VARIABLETYPE_INTEGER, 0, '', 'Gauge'],
        ];
        foreach ($profiles as $name => [$type, $digits, $suffix, $icon]) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, $type);
            if ($type === VARIABLETYPE_FLOAT) {
                IPS_SetVariableProfileDigits($name, $digits);
            }
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }
}
