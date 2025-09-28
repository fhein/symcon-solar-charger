<?php<?php



return [return [

    'properties' => [    'properties' => [

        'energyGateway' => [        'energyGateway' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '0',            'default' => '0',

        ],        ],

        'warp2Gateway' => [        'warp2Gateway' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '0',            'default' => '0',

        ],        ],

        'standbyBaseload' => [        'standbyBaseload' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '300',            'default' => '300',

        ],        ],

        'minSunPower' => [        'minSunPower' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '300.0',            'default' => '300.0',

        ],        ],

        'maxDischargePower' => [        'maxDischargePower' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '3800.0',            'default' => '3800.0',

        ],        ],

        'minChargerCurrent' => [        'minChargerCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '6000',            'default' => '6000',

        ],              ],      

        'maxChargerCurrent' => [        'maxChargerCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '16000',            'default' => '16000',

        ],        ],

        'minBatteryLevel' => [        'minBatteryLevel' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '15',            'default' => '15',

        ],        ],

        'targetCharger' => [        'targetCharger' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '0',            'default' => '0',

        ],        ],

        'host' => [        'host' => [

            'type' => 'String',            'type' => 'String',

            'default' => 'http://192.168.11.51',            'default' => 'http://192.168.11.51',

        ],        ],

        'user' => [        'user' => [

            'type' => 'String',            'type' => 'String',

            'default' => '',            'default' => '',

        ],        ],

        'password' => [        'password' => [

            'type' => 'String',            'type' => 'String',

            'default' => '',            'default' => '',

        ],        ],

        'updateInterval' => [        'updateInterval' => [

            'type' => 'Integer',            'type' => 'Integer',

            'default' => '20',            'default' => '20',

        ],        ],

        'enabled' => [        'enabled' => [

            'type' => 'Boolean',            'type' => 'Boolean',

            'default' => 'true',            'default' => 'true',

        ],        ],



    ],    ],

    'variables' => [    'variables' => [

        'batteryLevel' => [        'batteryLevel' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Battery Level',            'name' => 'Battery Level',

            'profile' => '~Intensity.100',            'profile' => '~Intensity.100',

            'position' => '1',            'position' => '1',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'batteryStatus' => [        'batteryStatus' => [

            'type' => 'String',            'type' => 'String',

            'name' => 'Battery Status',            'name' => 'Battery Status',

            'profile' => 'SOLAR.BatteryStatus',            'profile' => 'SOLAR.BatteryStatus',

            'position' => '2',            'position' => '2',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'batteryPower' => [        'batteryPower' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'Battery Power',            'name' => 'Battery Power',

            'profile' => 'SOLAR.BatteryPower',            'profile' => 'SOLAR.BatteryPower',

            'position' => '3',            'position' => '3',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'production' => [        'production' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'Production',            'name' => 'Production',

            'profile' => 'SOLAR.Production',            'profile' => 'SOLAR.Production',

            'position' => '4',            'position' => '4',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'consumption' => [        'consumption' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'Consumption',            'name' => 'Consumption',

            'profile' => 'SOLAR.Consumption',            'profile' => 'SOLAR.Consumption',

            'position' => '5',            'position' => '5',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'houseConsumption' => [        'houseConsumption' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'House Consumption (no battery)',            'name' => 'House Consumption (no battery)',

            'profile' => 'SOLAR.Consumption',            'profile' => 'SOLAR.Consumption',

            'position' => '7',            'position' => '7',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'import' => [        'import' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'Import / Export',            'name' => 'Import / Export',

            'profile' => 'SOLAR.Import',            'profile' => 'SOLAR.Import',

            'position' => '6',            'position' => '6',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'chargerMode' => [        'chargerMode' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Charger Mode',            'name' => 'Charger Mode',

            'profile' => 'SOLAR.ChargerMode',            'profile' => 'SOLAR.ChargerMode',

            'position' => '10',            'position' => '10',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'minChargerCurrent' => [        'minChargerCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Charger Current (min)',            'name' => 'Charger Current (min)',

            'profile' => 'SOLAR.CurrentMin',            'profile' => 'SOLAR.CurrentMin',

            'position' => '12',            'position' => '12',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'maxChargerCurrent' => [        'maxChargerCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Charger Current (max)',            'name' => 'Charger Current (max)',

            'profile' => 'SOLAR.CurrentMax',            'profile' => 'SOLAR.CurrentMax',

            'position' => '13',            'position' => '13',

            'default' => '16000',            'default' => '16000',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'chargerCurrent' => [        'chargerCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Charger Current',            'name' => 'Charger Current',

            'profile' => 'WARP2.ChargerCurrent',            'profile' => 'WARP2.ChargerCurrent',

            'position' => '15',            'position' => '15',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

        'chargerSetCurrent' => [        'chargerSetCurrent' => [

            'type' => 'Integer',            'type' => 'Integer',

            'name' => 'Set Charger Current',            'name' => 'Set Charger Current',

            'profile' => 'WARP2.ChargerCurrent',            'profile' => 'WARP2.ChargerCurrent',

            'position' => '17',            'position' => '17',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'chargerUpdate' => [        'chargerUpdate' => [

            'type' => 'Boolean',            'type' => 'Boolean',

            'name' => 'Update Charger now',            'name' => 'Update Charger now',

            'profile' => '~Switch',            'profile' => '~Switch',

            'position' => '18',            'position' => '18',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'chargerReboot' => [        'chargerReboot' => [

            'type' => 'Boolean',            'type' => 'Boolean',

            'name' => 'Reboot Charger',            'name' => 'Reboot Charger',

            'profile' => '~Switch',            'profile' => '~Switch',

            'position' => '19',            'position' => '19',

            'enableAction' => true,            'enableAction' => true,

        ],        ],

        'chargerPower' => [        'chargerPower' => [

            'type' => 'Float',            'type' => 'Float',

            'name' => 'Charger Power',            'name' => 'Charger Power',

            'profile' => 'SOLAR.ChargerPower',            'profile' => 'SOLAR.ChargerPower',

            'position' => '16',            'position' => '16',

            'enableAction' => false,            'enableAction' => false,

        ],        ],

                

                

    ],    ],

    'profiles' => [    'profiles' => [

        'SOLAR.BatteryStatus' => [        'SOLAR.BatteryStatus' => [

            'type' => 'String',            'type' => 'String',

            'icon' => 'Graph',            'icon' => 'Graph',

            'suffix' => '',            'suffix' => '',

        ],        ],

        'SOLAR.BatteryPower' => [        'SOLAR.BatteryPower' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'EnergyStorage',            'icon' => 'EnergyStorage',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.ChargerStatus' => [        'SOLAR.ChargerStatus' => [

            'type' => 'String',            'type' => 'String',

            'icon' => 'Garage',            'icon' => 'Garage',

            'suffix' => '',            'suffix' => '',

        ],        ],

        'SOLAR.Production' => [        'SOLAR.Production' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'Sun',            'icon' => 'Sun',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.Import' => [        'SOLAR.Import' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'EnergySolar',            'icon' => 'EnergySolar',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.Consumption' => [        'SOLAR.Consumption' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'EnergyProduction',            'icon' => 'EnergyProduction',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.ChargerPower' => [        'SOLAR.ChargerPower' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'Graph',            'icon' => 'Graph',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.Electricity' => [        'SOLAR.Electricity' => [

            'type' => 'Float',            'type' => 'Float',

            'icon' => 'Electricity',            'icon' => 'Electricity',

            'suffix' => ' kW',            'suffix' => ' kW',

            'digits' => '1',            'digits' => '1',

        ],        ],

        'SOLAR.Baseload' => [        'SOLAR.Baseload' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => 'Intensity',            'icon' => 'Intensity',

            'suffix' => ' W',            'suffix' => ' W',

            'minimum' => 0,            'minimum' => 0,

            'maximum' => 500,            'maximum' => 500,

        ],        ],

        'SOLAR.LEDStatus' => [        'SOLAR.LEDStatus' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => 'Eyes',            'icon' => 'Eyes',

            'suffix' => '',            'suffix' => '',

        ],        ],

        'SOLAR.Capacity' => [        'SOLAR.Capacity' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => 'EnergyStorage',            'icon' => 'EnergyStorage',

            'suffix' => ' kWh',            'suffix' => ' kWh',

        ],        ],

        'SOLAR.Information' => [        'SOLAR.Information' => [

            'type' => 'String',            'type' => 'String',

            'icon' => 'Information',            'icon' => 'Information',

            'suffix' => '',            'suffix' => '',

        ],        ],

        'SOLAR.UpdateInterval' => [        'SOLAR.UpdateInterval' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => 'Clock',            'icon' => 'Clock',

            'suffix' => ' s',            'suffix' => ' s',

        ],        ],

        'SOLAR.YesNo' => [        'SOLAR.YesNo' => [

            'type' => 'Boolean',            'type' => 'Boolean',

            'icon' => '',            'icon' => '',

            'associations' => [            'associations' => [

                [                 [ 

                    'value' => false,                     'value' => false, 

                    'text' => 'no',                     'text' => 'no', 

                    'icon' => 'Cross',                    'icon' => 'Cross',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => true,                     'value' => true, 

                    'text' => 'yes',                     'text' => 'yes', 

                    'icon' => 'Ok',                    'icon' => 'Ok',

                    'color' => -1,                    'color' => -1,

                ],                ],

            ],            ],

        ],        ],

        'SOLAR.CurrentMax' => [        'SOLAR.CurrentMax' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => '',            'icon' => '',

            'associations' => [            'associations' => [

                [                 [ 

                    'value' => 6000,                     'value' => 6000, 

                    'text' => '4,1 kW',                     'text' => '4,1 kW', 

                    'icon' => 'HollowArrowDown',                    'icon' => 'HollowArrowDown',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 10870,                     'value' => 10870, 

                    'text' => '7,5 kW',                     'text' => '7,5 kW', 

                    'icon' => 'HollowArrowDown',                    'icon' => 'HollowArrowDown',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 16000,                     'value' => 16000, 

                    'text' => '11,0 kW',                     'text' => '11,0 kW', 

                    'icon' => 'HollowDoubleArrowDown',                    'icon' => 'HollowDoubleArrowDown',

                    'color' => -1,                    'color' => -1,

                ],                ],

            ],            ],

        ],        ],

        'SOLAR.CurrentMin' => [        'SOLAR.CurrentMin' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => '',            'icon' => '',

            'associations' => [            'associations' => [

                [                 [ 

                    'value' => 0,                     'value' => 0, 

                    'text' => '0,0 kW',                     'text' => '0,0 kW', 

                    'icon' => 'Cross',                    'icon' => 'Cross',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 6000,                     'value' => 6000, 

                    'text' => '4,1 kW',                     'text' => '4,1 kW', 

                    'icon' => 'HollowArrowUp',                    'icon' => 'HollowArrowUp',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 10870,                     'value' => 10870, 

                    'text' => '7,5 kW',                     'text' => '7,5 kW', 

                    'icon' => 'HollowArrowUp',                    'icon' => 'HollowArrowUp',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 16000,                     'value' => 16000, 

                    'text' => '11,0 kW',                     'text' => '11,0 kW', 

                    'icon' => 'HollowDoubleArrowUp',                    'icon' => 'HollowDoubleArrowUp',

                    'color' => -1,                    'color' => -1,

                ],                ],

            ],            ],

        ],        ],

        'SOLAR.ChargerMode' => [        'SOLAR.ChargerMode' => [

            'type' => 'Integer',            'type' => 'Integer',

            'icon' => 'Intensity',            'icon' => 'Intensity',

            'associations' => [            'associations' => [

                [                 [ 

                    'value' => 0,                     'value' => 0, 

                    'text' => 'Sonne',                     'text' => 'Sonne', 

                    'icon' => 'Sun',                    'icon' => 'Sun',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 1,                     'value' => 1, 

                    'text' => 'Sonne + Batterie',                     'text' => 'Sonne + Batterie', 

                    'icon' => 'Battery',                    'icon' => 'Battery',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                 [ 

                    'value' => 2,                     'value' => 2, 

                    'text' => 'nur am Tag',                     'text' => 'nur am Tag', 

                    'icon' => 'EnergySolar',                    'icon' => 'EnergySolar',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                [

                    'value' => 3,                    'value' => 3,

                    'text' => 'fest',                    'text' => 'fest',

                    'icon' => 'Electricity',                    'icon' => 'Electricity',

                    'color' => -1,                    'color' => -1,

                ],                ],

                [                [

                    'value' => 4,                    'value' => 4,

                    'text' => 'aus',                    'text' => 'aus',

                    'icon' => 'Power',                    'icon' => 'Power',

                    'color' => -1,                    'color' => -1,

                ],                ],

            ],            ],

        ],        ],

                

    ],    ],

];];

