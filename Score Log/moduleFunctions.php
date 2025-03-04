<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\User\RoleGateway;
use Gibbon\Domain\DataSet;
use Gibbon\Module\HelpDesk\Data\Setting;
use Gibbon\Module\HelpDesk\Data\SettingManager;
use Psr\Container\ContainerInterface;

function getSettings(ContainerInterface $container) {
    $settingManager = new SettingManager($container->get(SettingGateway::class), 'Help Desk');

    $settingManager->addSetting('simpleCategories')
        ->setRenderer(function($data, $row) {
            $row->addCheckbox($data['name'])
                ->checked(intval($data['value']));
        })
        ->setProcessor(function($data) {
            return $data !== null ? 1 : 0;
        });

    $settingManager->addSetting('issueCategory')
        ->setRenderer(function($data, $row) {
            $row->addTextArea($data['name'])
                ->setValue($data['value']);
        })
        ->setProcessor(function($data) {
            return implode(',', explodeTrim($data ?? ''));
        });

    $settingManager->addSetting('issuePriority')
        ->setRenderer(function($data, $row) {
            $row->addTextArea($data['name'])
                ->setValue($data['value']);
        })
        ->setProcessor(function($data) {
            return implode(',', explodeTrim($data ?? ''));
        });

    $settingManager->addSetting('issuePriorityName')
        ->setRenderer(function($data, $row) {
            $row->addTextField($data['name'])
                ->setValue($data['value'])
                ->required();
        })
        ->setProcessor(function($data) {
            return empty($data) ? false : $data;
        });

    $settingManager->addSetting('techNotes')
        ->setRenderer(function($data, $row) {
            $row->addCheckbox($data['name'])
                ->checked(intval($data['value']));
        })
        ->setProcessor(function($data) {
            return $data !== null ? 1 : 0;
        });

    return $settingManager;
}


function explodeTrim($commaSeperatedString) {
    //This could, in theory, be made for effiicent, however, I don't care to do so.
    return array_filter(array_map('trim', explode(',', $commaSeperatedString)));
}

function getRoles(ContainerInterface $container) {
	$roleGateway = $container->get(RoleGateway::class);
    $criteria = $roleGateway->newQueryCriteria()
        ->sortBy(['gibbonRole.name']);

    return array_reduce($roleGateway->queryRoles($criteria)->toArray(), function ($group, $role) {
        $group[$role['gibbonRoleID']] = __($role['name']) . ' (' . __($role['category']) . ')';
        return $group; 
    }, []);
}

function statsOverview(DataSet $logs) {
    //Count each log entry
	$items = array_count_values($logs->getColumn('title'));

    //Sort by the title of the entry
    ksort($items);

    //Map the associative array to be displayed in the table
    array_walk($items, function (&$value, $key) {
        $value = ['name' => $key, 'value' => $value];
    });

    return $items;
}

function formatExpandableSection($title, $content) {
    $output = '';

    $output .= '<h6>' . $title . '</h6></br>';
    $output .= nl2brr($content);

    return $output;
}

/**
 * 计算权限值
 *
 * @param string $staff   默认 "N"，当为 "Y" 时表示有 staff 权限（权值 8）
 * @param string $student 默认 "N"，当为 "Y" 时表示有 student 权限（权值 4）
 * @param string $parent  默认 "N"，当为 "Y" 时表示有 parent 权限（权值 2）
 * @param string $other   默认 "N"，当为 "Y" 时表示有 other 权限（权值 1）
 *
 * @return int 返回权限值的整数表示
 */
function calcPermission($staff = "N", $student = "N", $parent = "N", $other = "N") {
    // 确保所有输入都是字符串，否则转换为 "N"
    $staff   = is_string($staff)   ? $staff   : "N";
    $student = is_string($student) ? $student : "N";
    $parent  = is_string($parent)  ? $parent  : "N";
    $other   = is_string($other)   ? $other   : "N";

    // 只有当参数严格等于 "Y" 时才认为是 "Y"，否则统一识别为 "N"
    $staff   = ($staff === "Y")   ? "Y" : "N";
    $student = ($student === "Y") ? "Y" : "N";
    $parent  = ($parent === "Y")  ? "Y" : "N";
    $other   = ($other === "Y")   ? "Y" : "N";

    $value = 0;
    // 按照题目要求，映射对应的二进制位：
    // staff -> 8 (二进制 1000)
    // student -> 4 (二进制 0100)
    // parent -> 2 (二进制 0010)
    // other -> 1 (二进制 0001)
    if ($staff === "Y") {
        $value |= 8;
    }
    if ($student === "Y") {
        $value |= 4;
    }
    if ($parent === "Y") {
        $value |= 2;
    }
    if ($other === "Y") {
        $value |= 1;
    }
    return $value;
}

/**
 * 将一个0-15的整数转换为4位二进制字符串，并将1替换为Y、0替换为N
 *
 * @param int $num 要转换的数字
 * @return string 转换后的字符串，如15转换为"YYYY"，0转换为"NNNN"
 */
function convertIntToYN($num) {
    // 如果传入的数字不在0-15范围内，则置为0
    if ($num < 0 || $num > 15) {
        $num = 0;
    }
    
    // 将数字转换为二进制字符串，并补齐到4位（不足4位的前面补0）
    $binary = str_pad(decbin($num), 4, '0', STR_PAD_LEFT);
    
    // 将二进制字符串中的'1'替换为'Y'，'0'替换为'N'
    $result = strtr($binary, ['1' => 'Y', '0' => 'N']);
    
    return $result;
}

/**
 * 获取数字在第 $position 位的值，数字范围限定在 0～15（超出视为 0）
 * 位编号从右侧开始：第1位为最低有效位，依次向左
 *
 * @param int $num      输入数字
 * @param int $position 位的位置（1～4）
 * @return int          返回 1 或 0
 */
function getBitAtPosition($num, $position) {
    // 如果传入的是字符串且是数值，则将其转换为整数（使用 base 10 转换，防止出现八进制问题）
    if (is_string($value) && is_numeric($value)) {
        $value = intval($value, 10);
    } else {
        // 对于其他类型，也直接转为整数
        $value = (int)$value;
    }
    if ($num < 0 || $num > 15) {
        $num = 0;
    }
    if ($position < 1 || $position > 4) {
        return 0;
    }
    // 对于 4 位数，从左数时，相当于右移 (4 - $position) 位
    return (($num >> (4 - $position)) & 1) ? 'Y' : 'N';
}