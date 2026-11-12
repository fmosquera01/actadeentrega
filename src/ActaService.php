<?php
declare(strict_types=1);

final class PluginActadeentregaActaService
{
    public const MANAGER_TABLE = 'glpi_plugin_fields_usercategorygerentetwos';
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
                'cat.name AS department', 'title.name AS jobtitle', 'loc.id AS area_location_id',
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
        $row['area'] = self::getLocationPath((int) ($row['area_location_id'] ?? 0));
        $row['supervisor'] = trim(($row['sup_firstname'] ?? '') . ' ' . ($row['sup_realname'] ?? ''));
        if ($row['supervisor'] === '') $row['supervisor'] = (string) ($row['sup_login'] ?? '');
        // El gerente se obtiene de la categoría del usuario (departamento).
        // El supervisor es un dato independiente y puede estar vacío.
        $row['manager'] = self::getDepartmentManager((int) $row['usercategories_id']);
        return $row;
    }

    /**
     * Devuelve la ubicación completa desde el nivel superior hasta la ubicación asignada.
     * Ejemplo: S1 > PRODUCCIÓN. Soporta jerarquías de varios niveles.
     */
    private static function getLocationPath(int $locationId): string
    {
        global $DB;
        if ($locationId < 1) return '';

        $segments = [];
        $visited = [];
        $currentId = $locationId;

        while ($currentId > 0 && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $location = $DB->request([
                'SELECT' => ['id', 'name', 'locations_id'],
                'FROM' => 'glpi_locations',
                'WHERE' => ['id' => $currentId],
                'LIMIT' => 1,
            ])->current();

            if (!$location) break;
            $name = trim((string) ($location['name'] ?? ''));
            if ($name !== '') array_unshift($segments, $name);
            $currentId = (int) ($location['locations_id'] ?? 0);
        }

        return implode(' > ', $segments);
    }

    private static function getDepartmentManager(int $categoryId): string
    {
        global $DB;
        if ($categoryId < 1 || !$DB->tableExists(self::MANAGER_TABLE)
            || !$DB->fieldExists(self::MANAGER_TABLE, self::MANAGER_FIELD)
            || !$DB->fieldExists(self::MANAGER_TABLE, 'items_id')) {
            return '';
        }

        // La tabla generada por Fields identifica la categoría mediante items_id.
        // No se valida contra la tabla global de metadatos: su estructura/nombres
        // pueden variar entre versiones del plugin Fields y no deben impedir la lectura del valor.


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

    /**
     * Descubre activos nativos y clases dinámicas creadas mediante Asset Definition.
     * Las definiciones personalizadas pueden compartir tabla; por eso se filtra
     * también por assets_assetdefinitions_id.
     */
    public static function getAssets(int $userId): array
    {
        global $DB;
        if ($userId < 1) return [];

        $assets = [];
        $itemtypes = array_keys(self::ASSET_TYPES);

        $managerClass = 'Glpi\\Asset\\AssetDefinitionManager';
        $assetBaseClass = 'Glpi\\Asset\\Asset';
        if (class_exists($managerClass)) {
            try {
                $assetManager = $managerClass::getInstance();
                $assetManager->bootDefinitions();

                foreach ($assetManager->getDefinitions(true) as $definition) {
                    $itemtype = $definition->getAssetClassName();
                    if (
                        is_string($itemtype)
                        && class_exists($itemtype)
                        && is_a($itemtype, $assetBaseClass, true)
                        && is_a($itemtype, CommonDBTM::class, true)
                    ) {
                        $itemtypes[] = $itemtype;
                    }
                }
            } catch (Throwable $e) {
                // Una definición inactiva o incompleta no debe impedir listar
                // los activos nativos ni las demás definiciones disponibles.
            }
        }

        foreach (array_values(array_unique($itemtypes)) as $itemtype) {
            try {
                if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
                    continue;
                }

                $table = $itemtype::getTable();
                if (!$table || !$DB->tableExists($table) || !$DB->fieldExists($table, 'users_id')) {
                    continue;
                }

                $config = self::ASSET_TYPES[$itemtype] ?? [];
                $select = ['id', 'users_id'];
                $optionalFields = [
                    'name', 'serial', 'otherserial', 'comment',
                    'manufacturers_id', 'locations_id', 'entities_id',
                    'is_deleted', 'is_template',
                ];
                foreach ($optionalFields as $field) {
                    if ($DB->fieldExists($table, $field)) $select[] = $field;
                }

                $modelField = (string) ($config['model'] ?? '');
                $modelTable = '';
                if ($modelField === '') {
                    // En Asset Definitions, los dropdowns de modelo suelen
                    // conservar la convención <prefijo>models_id.
                    foreach ($DB->listFields($table) as $fieldName => $fieldInfo) {
                        if (!is_string($fieldName) || !preg_match('/^[a-z0-9_]+models_id$/i', $fieldName)) {
                            continue;
                        }
                        $candidateTable = 'glpi_' . substr($fieldName, 0, -3);
                        if ($DB->tableExists($candidateTable)) {
                            $modelField = $fieldName;
                            $modelTable = $candidateTable;
                            break;
                        }
                    }
                } elseif ($DB->fieldExists($table, $modelField)) {
                    $prefix = substr($modelField, 0, -strlen('models_id'));
                    $candidateTable = 'glpi_' . $prefix . 'models';
                    if ($DB->tableExists($candidateTable)) $modelTable = $candidateTable;
                }
                if ($modelField !== '' && $DB->fieldExists($table, $modelField)) {
                    $select[] = $modelField;
                }

                $assetTypeField = (string) ($config['asset_type'] ?? '');
                $assetTypeTable = (string) ($config['asset_type_table'] ?? '');
                if ($assetTypeField !== '' && $DB->fieldExists($table, $assetTypeField)) {
                    $select[] = $assetTypeField;
                } else {
                    $assetTypeField = '';
                    $assetTypeTable = '';
                }

                $where = ['users_id' => $userId];
                if ($DB->fieldExists($table, 'is_deleted')) $where['is_deleted'] = 0;
                if ($DB->fieldExists($table, 'is_template')) $where['is_template'] = 0;

                $isCustom = is_a($itemtype, $assetBaseClass, true);
                if ($isCustom && $DB->fieldExists($table, 'assets_assetdefinitions_id')) {
                    $definition = $itemtype::getDefinition();
                    $definitionId = (int) ($definition->fields['id'] ?? 0);
                    if ($definitionId < 1) continue;
                    $where['assets_assetdefinitions_id'] = $definitionId;
                }

                $params = [
                    'SELECT' => array_values(array_unique($select)),
                    'FROM' => $table,
                    'WHERE' => $where,
                    'ORDER' => ['name ASC'],
                ];
                if ($DB->fieldExists($table, 'entities_id')) {
                    $entities = Session::getActiveEntities();
                    if ($entities === []) continue;
                    $params['WHERE']['entities_id'] = $entities;
                }

                foreach ($DB->request($params) as $row) {
                    $row['_itemtype'] = $itemtype;
                    $row['_label'] = $config['label'] ?? $itemtype::getTypeName(1);
                    $row['_table'] = $table;
                    $row['_model_field'] = $modelField;
                    $row['_model_table'] = $modelTable;
                    $row['_asset_type_field'] = $assetTypeField;
                    $row['_asset_type_table'] = $assetTypeTable;
                    $assets[] = $row;
                }
            } catch (Throwable $e) {
                // Saltar solo el tipo que no pueda consultarse.
                continue;
            }
        }

        usort($assets, static fn(array $a, array $b): int => strcasecmp(
            (string) ($a['_label'] ?? '') . ' ' . (string) ($a['name'] ?? ''),
            (string) ($b['_label'] ?? '') . ' ' . (string) ($b['name'] ?? '')
        ));

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
            $modelTable = (string) ($asset['_model_table'] ?? '');
            if ($modelTable === '') {
                $modelTable = match ($asset['_itemtype'] ?? '') {
                    'Computer' => 'glpi_computermodels', 'Monitor' => 'glpi_monitormodels',
                    'Phone' => 'glpi_phonemodels', 'Printer' => 'glpi_printermodels',
                    'Peripheral' => 'glpi_peripheralmodels', 'NetworkEquipment' => 'glpi_networkequipmentmodels',
                    'PDU' => 'glpi_pdumodels', 'Enclosure' => 'glpi_enclosuremodels', 'Rack' => 'glpi_rackmodels',
                    default => '',
                };
            }
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
