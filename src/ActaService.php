<?php
declare(strict_types=1);

final class PluginActadeentregaActaService
{
    public const MANAGER_TABLE = 'glpi_plugin_fields_usercategoriesgerente2';
    public const MANAGER_FIELD = 'users_id_gerentedeareafield';
    public const MANAGER_CONTAINER_ID = 12;
    public const MANAGER_FIELD_ID = 17;

    private const ASSET_TYPES = [
        'Computer' => ['table' => 'glpi_computers', 'label' => 'COMPUTADORA', 'model' => 'computermodels_id', 'asset_type' => 'computertypes_id', 'asset_type_table' => 'glpi_computertypes'],
        'Monitor' => ['table' => 'glpi_monitors', 'label' => 'MONITOR', 'model' => 'monitormodels_id'],
        'Phone' => ['table' => 'glpi_phones', 'label' => 'TELÉFONO', 'model' => 'phonemodels_id'],
        'Printer' => ['table' => 'glpi_printers', 'label' => 'IMPRESORA', 'model' => 'printermodels_id'],
        'Peripheral' => ['table' => 'glpi_peripherals', 'label' => 'PERIFÉRICO', 'model' => 'peripheralmodels_id'],
        'NetworkEquipment' => ['table' => 'glpi_networkequipments', 'label' => 'EQUIPO DE RED', 'model' => 'networkequipmentmodels_id'],
        'PDU' => ['table' => 'glpi_pdus', 'label' => 'PDU', 'model' => 'pdumodels_id'],
        'Enclosure' => ['table' => 'glpi_enclosures', 'label' => 'GABINETE', 'model' => 'enclosuremodels_id'],
        'Rack' => ['table' => 'glpi_racks', 'label' => 'RACK', 'model' => 'rackmodels_id'],
    ];

    public static function getUser(int $id): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => [
                'u.id', 'u.firstname', 'u.realname', 'u.name', 'u.usercategories_id',
                'u.usertitles_id', 'u.users_id_supervisor', 'u.locations_id',
                'cat.name AS department', 'title.name AS jobtitle', 'loc.name AS area',
                'sup.firstname AS sup_firstname', 'sup.realname AS sup_realname', 'sup.name AS sup_login',
            ],
            'FROM' => 'glpi_users AS u',
            'LEFT JOIN' => [
                'glpi_usercategories AS cat' => ['ON' => ['u' => 'usercategories_id', 'cat' => 'id']],
                'glpi_usertitles AS title' => ['ON' => ['u' => 'usertitles_id', 'title' => 'id']],
                'glpi_locations AS loc' => ['ON' => ['u' => 'locations_id', 'loc' => 'id']],
                'glpi_users AS sup' => ['ON' => ['u' => 'users_id_supervisor', 'sup' => 'id']],
            ],
            'WHERE' => ['u.id' => $id, 'u.is_deleted' => 0, 'u.is_active' => 1],
            'LIMIT' => 1,
        ])->current();
        if (!$row) return [];
        $row['recipient'] = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
        if ($row['recipient'] === '') $row['recipient'] = (string) $row['name'];
        $row['supervisor'] = trim(($row['sup_firstname'] ?? '') . ' ' . ($row['sup_realname'] ?? ''));
        if ($row['supervisor'] === '') $row['supervisor'] = (string) ($row['sup_login'] ?? '');
        // El gerente se obtiene de la categoría del usuario (departamento).
        // El supervisor es un dato independiente y puede estar vacío.
        $row['manager'] = self::getDepartmentManager((int) $row['usercategories_id']);
        return $row;
    }

    private static function getDepartmentManager(int $categoryId): string
    {
        global $DB;
        if ($categoryId < 1 || !$DB->tableExists(self::MANAGER_TABLE)
            || !$DB->fieldExists(self::MANAGER_TABLE, self::MANAGER_FIELD)
            || !$DB->fieldExists(self::MANAGER_TABLE, 'items_id')) {
            return '';
        }

        // Verifica que el campo configurado corresponda al bloque y campo
        // indicados en GLPI: Bloque -Gerente2 (12), campo (17).
        if ($DB->tableExists('glpi_plugin_fields_fields')) {
            $fieldMeta = $DB->request([
                'SELECT' => ['id', 'plugin_fields_containers_id', 'name'],
                'FROM' => 'glpi_plugin_fields_fields',
                'WHERE' => [
                    'id' => self::MANAGER_FIELD_ID,
                    'plugin_fields_containers_id' => self::MANAGER_CONTAINER_ID,
                ],
                'LIMIT' => 1,
            ])->current();
            if (!$fieldMeta || (string)$fieldMeta['name'] !== self::MANAGER_FIELD) {
                return '';
            }
        }

        $row = $DB->request([
            'SELECT' => ['manager.firstname', 'manager.realname', 'manager.name'],
            'FROM' => self::MANAGER_TABLE . ' AS department_fields',
            'LEFT JOIN' => [
                'glpi_users AS manager' => [
                    'ON' => ['department_fields' => self::MANAGER_FIELD, 'manager' => 'id'],
                ],
            ],
            'WHERE' => ['department_fields.items_id' => $categoryId],
            'LIMIT' => 1,
        ])->current();

        if (!$row) return '';
        $name = trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['realname'] ?? ''));
        return $name !== '' ? $name : trim((string)($row['name'] ?? ''));
    }

    public static function getAssets(int $userId): array
    {
        global $DB;
        $assets = [];
        foreach (self::ASSET_TYPES as $itemtype => $config) {
            $table = $config['table'];
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'users_id')) continue;
            $select = ['id', 'name', 'serial', 'otherserial', 'comment', 'users_id', 'manufacturers_id', 'locations_id'];
            $modelField = $config['model'];
            if ($DB->fieldExists($table, $modelField)) $select[] = $modelField;
            $assetTypeField = $config['asset_type'] ?? '';
            if ($assetTypeField !== '' && $DB->fieldExists($table, $assetTypeField)) $select[] = $assetTypeField;
            foreach ($DB->request([
                'SELECT' => $select, 'FROM' => $table,
                'WHERE' => ['users_id' => $userId, 'is_deleted' => 0, 'is_template' => 0],
                'ORDER' => ['name ASC'],
            ]) as $row) {
                $row['_itemtype'] = $itemtype;
                $row['_label'] = $config['label'];
                $row['_table'] = $table;
                $row['_model_field'] = $config['model'];
                $row['_asset_type_field'] = $assetTypeField;
                $row['_asset_type_table'] = $config['asset_type_table'] ?? '';
                $assets[] = $row;
            }
        }
        return $assets;
    }

    public static function describeAsset(array $asset): string
    {
        $parts = [];
        $assetTypeField = (string) ($asset['_asset_type_field'] ?? '');
        $assetTypeTable = (string) ($asset['_asset_type_table'] ?? '');
        $assetTypeId = (int) ($asset[$assetTypeField] ?? 0);
        if ($assetTypeField !== '' && $assetTypeTable !== '' && $assetTypeId > 0) {
            $assetTypeName = trim((string) Dropdown::getDropdownName($assetTypeTable, $assetTypeId));
            if ($assetTypeName !== '') $parts[] = $assetTypeName;
        }

        // En computadoras el nombre interno (por ejemplo, s906) no forma parte
        // de la descripción institucional solicitada.
        if (($asset['_itemtype'] ?? '') !== 'Computer'
            && trim((string) ($asset['name'] ?? '')) !== '') {
            $parts[] = trim((string) $asset['name']);
        }

        $manufacturer = (int) ($asset['manufacturers_id'] ?? 0);
        if ($manufacturer > 0) {
            $name = trim((string) Dropdown::getDropdownName('glpi_manufacturers', $manufacturer));
            if ($name !== '') $parts[] = $name;
        }

        $modelField = (string) ($asset['_model_field'] ?? '');
        $modelId = (int) ($asset[$modelField] ?? 0);
        if ($modelId > 0) {
            $modelTable = match ($asset['_itemtype'] ?? '') {
                'Computer' => 'glpi_computermodels', 'Monitor' => 'glpi_monitormodels',
                'Phone' => 'glpi_phonemodels', 'Printer' => 'glpi_printermodels',
                'Peripheral' => 'glpi_peripheralmodels', 'NetworkEquipment' => 'glpi_networkequipmentmodels',
                'PDU' => 'glpi_pdumodels', 'Enclosure' => 'glpi_enclosuremodels', 'Rack' => 'glpi_rackmodels',
                default => '',
            };
            if ($modelTable !== '') {
                $name = trim((string) Dropdown::getDropdownName($modelTable, $modelId));
                if ($name !== '') $parts[] = $name;
            }
        }

        if (($asset['_itemtype'] ?? '') === 'Computer') {
            $parts = array_merge($parts, self::computerSpecifications((int)($asset['id'] ?? 0)));
        }

        $comment = trim((string) ($asset['comment'] ?? ''));
        if ($comment !== '') $parts[] = $comment;

        return implode(', ', array_values(array_filter($parts, static fn($v) => trim((string)$v) !== '')));
    }

    private static function computerSpecifications(int $computerId): array
    {
        if ($computerId < 1) return [];
        $builder = 'GlpiPlugin\\Informeglopi\\AssetSnapshotBuilder';
        if (!class_exists($builder)) {
            $file = GLPI_ROOT . '/plugins/informeglpi/src/AssetSnapshotBuilder.php';
            if (is_file($file)) require_once $file;
        }
        if (!class_exists($builder)) return [];

        try {
            $computer = new Computer();
            if (!$computer->getFromDB($computerId)) return [];
            $snapshot = $builder::build($computer);
            $components = $snapshot['components'] ?? [];
            $lines = [];

            foreach (($components['processors'] ?? []) as $component) {
                $detail = trim((string)($component['device']['report_label'] ?? ''));
                if ($detail !== '') $lines[] = $detail;
            }

            foreach (($components['memories'] ?? []) as $component) {
                $device = $component['device'] ?? [];
                $relation = $component['relation'] ?? [];

                $typeId = (int)($device['devicememorytypes_id'] ?? 0);
                $memoryType = $typeId > 0
                    ? trim((string)Dropdown::getDropdownName('glpi_devicememorytypes', $typeId))
                    : '';
                $frequency = (int)($device['frequence'] ?? $device['frequency'] ?? 0);
                $designation = trim((string)($device['designation'] ?? ''));
                $size = (float)($relation['size'] ?? $device['size_default'] ?? $device['size'] ?? 0);
                $sizeGb = $size > 0 ? (int)round($size / 1024) : 0;

                $memoryParts = [];
                if ($memoryType !== '') $memoryParts[] = $memoryType;
                if ($frequency > 0) $memoryParts[] = (string)$frequency;
                if ($designation !== '') $memoryParts[] = $designation;
                $memoryLabel = implode(' - ', $memoryParts);
                if ($sizeGb > 0) $memoryLabel .= ($memoryLabel !== '' ? ' ' : '') . $sizeGb . ' GB';
                if ($memoryLabel === '') {
                    $memoryLabel = trim((string)($device['report_label'] ?? ''));
                }
                if ($memoryLabel !== '') $lines[] = $memoryLabel;
            }

            foreach (($components['hard_drives'] ?? []) as $component) {
                $detail = trim((string)($component['device']['report_label'] ?? ''));
                if ($detail !== '') $lines[] = $detail;
            }

            return $lines;
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function missing(array $user, array $assets, bool $signatureExists): array
    {
        $missing = [];
        foreach ([
            'recipient' => 'Responsable del equipo (usuario GLPI)',
            'department' => 'Departamento (categoría del usuario)',
            'jobtitle' => 'Cargo (título del usuario)',
            'area' => 'Área (ubicación del usuario)',
            'manager' => 'Gerente del departamento (categoría del usuario)',
        ] as $key => $label) {
            if (trim((string) ($user[$key] ?? '')) === '') $missing[] = $label;
        }
        if (!$signatureExists) $missing[] = 'Firma personal del técnico (registrarla en su perfil GLPI)';
        if (!$assets) $missing[] = 'Seleccione al menos un activo asignado actualmente al colaborador';
        foreach ($assets as $asset) {
            if (trim((string) ($asset['serial'] ?? '')) === '') {
                $missing[] = 'Número de serie del activo: ' . (string) ($asset['name'] ?? $asset['id']);
            }
        }
        return $missing;
    }

    public static function authorizeSignature(int $userId, string $pin): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
            throw new RuntimeException('No está instalada la tabla de firmas de Informe Técnico.');
        }
        $row = $DB->request([
            'SELECT' => ['signature_path', 'signature_sha256', 'pin_hash', 'failed_attempts', 'locked_until'],
            'FROM' => 'glpi_plugin_informeglopi_user_signatures',
            'WHERE' => ['users_id' => $userId], 'LIMIT' => 1,
        ])->current();
        if (!$row) throw new RuntimeException('El técnico no tiene una firma personal registrada en GLPI.');
        if (!preg_match('/^[0-9]{6}$/', $pin)) throw new RuntimeException('Ingrese el PIN personal de 6 dígitos para autorizar su firma.');
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            throw new RuntimeException('El PIN está bloqueado temporalmente por intentos fallidos.');
        }
        if (!password_verify($pin, (string) $row['pin_hash'])) {
            $failures = (int) $row['failed_attempts'] + 1;
            $locked = $failures >= 5;
            $DB->update('glpi_plugin_informeglopi_user_signatures', [
                'failed_attempts' => $locked ? 0 : $failures,
                'locked_until' => $locked ? date('Y-m-d H:i:s', time() + 900) : null,
            ], ['users_id' => $userId]);
            throw new RuntimeException($locked ? 'PIN incorrecto. Firma bloqueada durante 15 minutos.' : 'PIN incorrecto.');
        }
        $relative = (string) $row['signature_path'];
        $base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/informeglpi/signatures/users');
        $path = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/' . ltrim($relative, '/'));
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('No se encontró el archivo privado de firma personal.');
        }
        $hash = hash_file('sha256', $path);
        if (!$hash || !hash_equals((string) $row['signature_sha256'], $hash)) {
            throw new RuntimeException('La firma personal no superó la verificación de integridad.');
        }
        $DB->update('glpi_plugin_informeglopi_user_signatures', ['failed_attempts' => 0, 'locked_until' => null], ['users_id' => $userId]);
        return ['path' => $path, 'sha256' => $hash, 'user' => getUserName($userId)];
    }
}
