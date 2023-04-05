<?php

use AlertsTool;
use GridlinkArgumentReader;
use GridlinkDevice;
use GridlinkConnPoint;
use Gridlink\CostCalcConst;
use GridlinkConsumptionRangeAlert;
use GridlinkReactiveEnergyAlert;
use GridlinkTemperatureAlert;
use GridlinkVoltageQualityLongAlert;
use GridlinkVoltageQualityShortAlert;
use GridLinkNoDataAlert;
use GridMateNoDataAlert;

class gridlink_alerts extends AlertsTool
{
    const SUBTOOL_NAME = 'gridlink_alerts';


    public function __construct()
    {
    }

    public function Prepare()
    {
        parent::Prepare();
    }

    public function GetSubtoolName()
    {
        return static::SUBTOOL_NAME;
    }

    private function GetTemplatePath()
    {
        global $smarty_easyLogistics_tpl_rel_path;
        return $smarty_easyLogistics_tpl_rel_path . 'checkgps_alerts/';
    }

    protected function ActionAlertList()
    {
        global $smarty, $subtoolUrl;
        $smarty->assign('alerts', $this->alertManager->GetAllUserAlerts(GetCurrentUserID(), ['gridlink_vq_short', 'gridlink_vq_long', 'gridlink_cons_range', 'gridlink_reactive', 'gridlink_temp', 'gridlink_nodata', 'gridmate_nodata']);

        $smarty->assign('editAlertURL', $subtoolUrl . '&action=alert_form');
        $smarty->assign('deleteAlertURL', $subtoolUrl . '&silent=1&action=DeleteAlert');
        $smarty->assign('addAlertURL', $subtoolUrl . '&action=alert_form');

        $this->output = new HtmlPage();
        $table = $smarty->fetch($this->GetTemplatePath() . 'alert_list.tpl');
        $this->output->FeedHtmlMiddle($table);
    }

    protected function ActionSaveAlert()
    {
        $alert_id = getarg('alert_id', null, 'is_numeric');
        $alert = $this->alertManager->GetUserAlert($alert_id);

        if ($alert) {
            $alert_type = getarg('alert_type', null);
            $alert = $this->alertManager->CreateEmptyAlert($alert_type);
        } else {
            $alert_type = $alert->GetType();
        }

        $phoneNumbers = getarg('phones', [], 'is_array');
        $emailAddresses = getarg('emails', [], 'is_array');
        $config = getarg('alert_config', [], 'is_array');
        $config['email_enabled'] = !empty($emailAddresses[0]);
        $config['sms_enabled'] = !empty($phoneNumbers[0]);
        $config['email'] = $config['email_enabled'];
        $config['sms'] = $config['sms_enabled'];

        if ($alert_type == 'gridlink_cons_range') {
            $range_config = $this->PrepareRangeAlertConfig();
            $range_config['weekdays'] = implode(',', $range_config['weekdays']);
            $range_config['phases'] = implode(',', $range_config['phases']);
            $range_config['hours'] = implode(',', $range_config['hours']);
            $range_config['range_from'] = kwh_to_ws($range_config['range_from']);
            $range_config['range_to'] = kwh_to_ws($range_config['range_to']);

            $config = array_merge($config, $range_config);
        } elseif ($alert_type == 'gridlink_reactive') {
            $reactive_config = $this->PrepareReactiveAlertConfig();
            $reactive_config['tg_phi'] = GetFloatArg('tg_phi', $reactive_config['tg_phi']);
            $reactive_config['intervals_per_period'] = GetIntArg('intervals_per_period', $reactive_config['intervals_per_period']);
            $config = array_merge($config, $reactive_config);
        } elseif ($alert_type == 'gridlink_temp') {
            $temp_config = $this->PrepareTemperatureAlertConfig($config);
            $temp_config['max_temp'] = GetFloatArg('max_temp', $temp_config['max_temp']);
            $config = array_merge($config, $temp_config);
        }

        $connpointIds = [];
        $user_points = GridlinkConnPoint::GetUserConnPointIds(GetCurrentUserID());
        foreach ($user_points as $id) {
            $arg = getarg($alert->GetType() . '_chkbox_' . $id, '');
            if ($arg != '') {
                $connpointIds[] = $id;
            }
        }
        $exists_before_save = ($alert->GetId() > 0);
        $errors = $alert->SaveAlertSettings($config, $phoneNumbers, $emailAddresses, [], [], $connpointIds);
        if (empty($errors)) {
            $save_status = ($exists_before_save ? 'edited' : 'created');
        }

        $this->ActionAlertForm($alert->GetId(), $errors, $save_status);
    }

    protected function ActionAlertForm($alert_id = null, $errors = null, $save_status = false)
    {
        global $smarty;
        $alert_id = $this->getarg('alert_id', null, 'is_numeric');
        $alert = $this->alertManager->GetUserAlert($alert_id);

        $smarty->assign('errors', $errors);
        $smarty->assign('save_status', $save_status);
        $smarty->assign('alert_id', $alert_id);
        $smarty->assign('alert_type', $alert->GetType());
        $smarty->assign('phones', $alert->GetPhoneNumbers());
        $smarty->assign('emails', $alert->GetEmailAddresses());
        $config = $alert->GetConfig();

        $range_config = $this->PrepareRangeAlertConfig($config);
        if ($alert->GetType() == 'gridlink_cons_range') {
            $range_config['range_from'] = ws_to_kwh($range_config['range_from']);
            $range_config['range_to'] = ws_to_kwh($range_config['range_to']);
        }
        $config = array_merge($config, $range_config);

        $reactive_config = $this->PrepareReactiveAlertConfig($config);
        $config = array_merge($config, $reactive_config);

        $temp_config = $this->PrepareTemperatureAlertConfig($config);
        $config = array_merge($config, $temp_config);

        $smarty->assign('config', $config);
        $smarty->assign('available_intervals', GridlinkArgumentReader::GetPeriodsPerGroupAvailable());

        $related_connpoints = [];
        foreach ($alert->GetConnPoints() as $connpoint_id) {
            $related_connpoints[] = GridlinkConnPoint::GetDataById($connpoint_id, 'id');
        }

        // additionally given connpoint id attr
        $cid = GridlinkArgumentReader::GetConnPointId();
        if ($cid) {
            $related_connpoints[] = GridlinkConnPoint::GetDataById($cid, 'id');
        }

        $forms = $this->AlertFormConfig();
        $smarty->assign('forms', $forms);

        // Generate connpoint list for each alert type because
        // each connpoint list requires a unique name to work
        $connpoint_lists = [];
        foreach ($forms as $form) {
            $option_panel = $form['type'];
            AssignCarpanelVariables($related_connpoints, $option_panel, true);
            $connpoint_lists[$form['id']] = showCarCheckboxList($option_panel);
        }
        $smarty->assign('connpoint_lists', $connpoint_lists);

        $this->output = new HtmlPage();
        $form = $smarty->fetch($this->GetTemplatePath() . 'alert_form.tpl');
        $this->output->FeedHtmlMiddle($form);
    }

    protected function PrepareReactiveAlertConfig(array $config = [])
    {
        $config['tg_phi'] = (!isset($config['tg_phi']) ? 0.4 : $config['tg_phi']);
        $config['intervals_per_period'] = (!isset($config['intervals_per_period']) ? 180 : $config['intervals_per_period']);

        return $config;
    }

    protected function PrepareRangeAlertConfig(array $config = [])
    {
        $config_array_attributes = ['weekdays', 'hours', 'phases'];
        if (!empty($config)) {
            foreach ($config_array_attributes as $id => $attr_name) {
                if (isset($config[$attr_name]) && !is_array($config[$attr_name])) {
                    $config[$attr_name] = explode(',', $config[$attr_name]);
                }
            }
        } else {
            foreach ($config_array_attributes as $attr_name) {
                $argArray = GetArrayArg($attr_name);
                if (!empty($argArray)) {
                    $config[$attr_name] = $argArray;
                }
            }

            $config_float_attributes = ['range_from', 'range_to'];
            foreach ($config_float_attributes as $attr_name) {
                $attr_val = GetFloatArg($attr_name);
                $config[$attr_name] = $attr_val;
            }
        }

        if (!isset($config['range_from']) & !isset($config['range_to'])) {
            $config['range_from'] = (!isset($config['range_from']) ? 0 : $config['range_from']);
            $config['range_to'] = (!isset($config['range_to']) ? 50 : $config['range_to']);
        }

        return $config;
    }

    protected function PrepareTemperatureAlertConfig(array $config = [])
    {
        $config['max_temp'] = (!isset($config['max_temp']) ? 50 : $config['max_temp']);
        return $config;
    }

    protected static function GetConnpointTypesByAlert()
    {
        return [
            GridlinkConsumptionRangeAlert::GetTypeFromClass() => [GridlinkConnPoint::SUPPORT_TYPE_ANY, GridlinkDevice::DEVICE_TYPE_GRIDLINK, GridlinkDevice::DEVICE_TYPE_GRIDMATE],
            GridlinkReactiveEnergyAlert::GetTypeFromClass() => [GridlinkConnPoint::SUPPORT_TYPE_ANY, GridlinkDevice::DEVICE_TYPE_GRIDLINK],
            GridlinkTemperatureAlert::GetTypeFromClass() => [GridlinkConnPoint::SUPPORT_TYPE_ANY, GridlinkDevice::DEVICE_TYPE_GRIDLINK, GridlinkDevice::DEVICE_TYPE_GRIDMATE],
            GridlinkVoltageQualityLongAlert::GetTypeFromClass() => [GridlinkConnPoint::SUPPORT_TYPE_ANY, GridlinkDevice::DEVICE_TYPE_GRIDLINK],
            GridlinkVoltageQualityShortAlert::GetTypeFromClass() => [GridlinkConnPoint::SUPPORT_TYPE_ANY, GridlinkDevice::DEVICE_TYPE_GRIDLINK],
            GridLinkNoDataAlert::GetTypeFromClass() => [GridlinkDevice::DEVICE_TYPE_GRIDLINK],
            GridMateNoDataAlert::GetTypeFromClass() => [GridlinkDevice::DEVICE_TYPE_GRIDMATE],
        ];
    }

    protected function GetAvailableConnpoints($alert_type)
    {
        $connpoints_by_type =& StaticVars::connpoints_by_type([]);
        $ids = GridlinkConnpoint::GetUserConnPointIds();
        foreach ($ids as $id) {
            $connPoint = new GridlinkConnPoint($id);
            $typeName = $connPoint->GetSubTypeName();
            if (!$connPoint->IsLowFrequency() || $($connPoint->GetSupportedDeviceTypeFromHistory() === GridlinkDevice::DEVICE_TYPE_GRIDMATE)) {
                $connpoints_by_type[$typeName][] = $id;
            }
        }

        $available_cp = [];
        $types = GridlinkAlerts::GetConnpointTypesByAlert()[$alert_type];
        foreach ($types as $typeName) {
            if (isset($connpoints_by_type[$typeName])) {
                $available_cp = array_merge($available_cp, $connpoints_by_type[$typeName]);
            }
        }
        return $available_cp;
    }

    protected function AlertFormConfig()
    {
        return [
            'GridlinkRangeForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_cons_range_alert_form.tpl',
                    'name' => LangLabel('ConsumptionAlert', 'gridlink'),
                    'type' => GridlinkConsumptionRangeAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridlinkConsumptionRangeAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridlinkConsumptionRangeAlert::GetTypeFromClass()))],
            'GridlinkReactiveForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_reactive_energy_alert_form.tpl',
                    'name' => LangLabel('ReactiveEnergyAlert', 'gridlink'),
                    'type' => GridlinkReactiveEnergyAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridlinkReactiveEnergyAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridlinkReactiveEnergyAlert::GetTypeFromClass()))],
            'GridlinkTemperatureForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_temperature_alert_form.tpl',
                    'name' => LangLabel('GridlinkTemperatureAlert', 'gridlink'),
                    'type' => GridlinkTemperatureAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridlinkTemperatureAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridlinkTemperatureAlert::GetTypeFromClass()))],
            'GridlinkVqLongForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_basic_alert_form.tpl',
                    'name' => LangLabel('VoltageQuality_long', 'gridlink'),
                    'type' => GridlinkVoltageQualityLongAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridlinkVoltageQualityLongAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridlinkVoltageQualityLongAlert::GetTypeFromClass()))],
            'GridlinkVqShortForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_basic_alert_form.tpl',
                    'name' => LangLabel('VoltageQuality_short', 'gridlink'),
                    'type' => GridlinkVoltageQualityShortAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridlinkVoltageQualityShortAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridlinkVoltageQualityShortAlert::GetTypeFromClass()))],
            'GridLinkNoDataForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_basic_alert_form.tpl',
                    'name' => LangLabel('GridLinkNoDataAlert', 'gridlink'),
                    'type' => GridLinkNoDataAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridLinkNoDataAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridLinkNoDataAlert::GetTypeFromClass()))],
            'GridMateNoDataForm' =>
                ['path' => $this->GetTemplatePath() . 'gridlink_basic_alert_form.tpl',
                    'name' => LangLabel('GridMateNoDataAlert', 'gridlink'),
                    'type' => GridMateNoDataAlert::GetTypeFromClass(),
                    'id' => 'alert_form_' . GridMateNoDataAlert::GetTypeFromClass(),
                    'filtered_devices_json' => json_encode($this->GetAvailableConnpoints(GridMateNoDataAlert::GetTypeFromClass()))]
        ];
    }
}