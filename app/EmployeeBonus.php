<?php

namespace App;

use App\Helpers\Util;
use Carbon;
use \Milon\Barcode\DNS2D;

class EmployeeBonus
{
  function __construct($contracts, $year, $pay_date, $procedure)
  {
    $employee = $contracts[0]->employee;

    $this->code_image = null;

    // Common data
    $this->employee_id = $employee->id;
    $this->year = $year;
    $this->month_shortened = 'AGUINALDO';
    $this->nua_cua = $employee->nua_cua;
    $this->ci = $employee->identity_card;
    $this->id_ext = $employee->city_identity_card->shortened;
    $this->insurance_company_id = null;
    $this->ci_ext = $this->ci . ' ' . $this->id_ext;
    $this->first_name = $employee->first_name;
    $this->second_name = $employee->second_name;
    $this->last_name = $employee->last_name;
    $this->mothers_last_name = $employee->mothers_last_name;
    $this->full_name = implode(" ", [$this->last_name, $this->mothers_last_name, $this->first_name, $this->second_name]);
    $this->account_number = $employee->account_number;
    $this->birth_date = Carbon::parse($employee->birth_date)->format('d/m/Y');
    $this->gender = $employee->gender;
    $this->charge = null;
    $this->position = null;
    $this->start_date = null;
    $this->end_date = null;
    $this->retirement_reason = "";
    $this->management_entity = $employee->management_entity->name;
    $this->management_entity_id = $employee->management_entity->id;
    $this->worked_days = (object)[
      'months' => 0,
      'days' => 0,
    ];
    $this->ovt = (object)[
      'insurance_company_id' => null,
      'management_entity_id' => $employee->management_entity->ovt_id,
      'contract_mode' => $employee->last_contract()->contract_mode->ovt_id,
      'contract_type' => $employee->last_contract()->contract_type->ovt_id,
    ];

    // Extra data
    $this->position_group = null;
    $this->position_group_id = null;
    $this->employer_number = null;
    $this->employer_number_id = null;
    $this->base_wages = $this->get_latest_payrolls($employee->id, $year, $pay_date, $procedure);
    $this->average = 0;
    $this->bonus_percentage = (object)[];
    $this->bonus = $this->calc_bonus($contracts, $year, $pay_date, $procedure);
  }

  private function calc_bonus($contracts, $year, $pay_date, $procedure)
  {
    $this->calc_worked_moths($contracts, $year, $pay_date);

    $worked_days = ($this->worked_days->months * 30 + $this->worked_days->days) / 360;

    if ($procedure->split_percentage) {
      $bonus_percentage = (object)[
        'first_split' => 0,
        'second_split' => 0,
      ];

      foreach ($this->base_wages as $wage) {
        if ($procedure->lower_limit_wage && $wage <= $procedure->lower_limit_wage) {
          $bonus_percentage->first_split += $wage;
          $bonus_percentage->second_split += $bonus_percentage->first_split;
        } else {
          $bonus_percentage->first_split += $wage * (100 - $procedure->split_percentage) / 100;
          $bonus_percentage->second_split += $wage * $procedure->split_percentage / 100;
        }
      }

      $this->bonus_percentage = (object)[
        'first_split' => (object)[
          'percentage' => (100 - $procedure->split_percentage),
          'value' => ($bonus_percentage->first_split / 3) * $worked_days
        ],
        'second_split' => (object)[
          'percentage' => $procedure->split_percentage,
          'value' => ($bonus_percentage->second_split / 3) * $worked_days
        ]
      ];
    }

    $this->average = array_sum($this->base_wages) / 3;
    return ($this->average * $worked_days);
  }

  private function get_latest_payrolls($employee_id, $year, $pay_date, $procedure)
  {
    $payrolls = Payroll::where('employee_id', $employee_id)->leftjoin('procedures as p', 'p.id', '=', 'payrolls.procedure_id')->where('p.year', $year)->leftjoin('months as m', 'm.id', '=', 'p.month_id')->orderBy('m.order', 'DESC')->with(['contract' => function ($q) {
      $q->orderBy('start_date', 'DESC');
    }])->where('m.order', '<', Carbon::parse($pay_date)->month)->select('payrolls.*', 'm.order');

    if ($procedure->upper_limit_wage) {
      $upper_limit_wage = $procedure->upper_limit_wage;
      $payrolls = $payrolls->with(['charge' => function ($q) use ($upper_limit_wage) {
        $q->where('base_wage', '<=', $upper_limit_wage);
      }]);
    }

    $payrolls = $payrolls->limit(3)->get()->all();

    if (count($payrolls) == 0) return [0];

    $this->set_actual_data($payrolls[0]);

    $payrolls = array_reverse($payrolls);

    $results = [];
    foreach ($payrolls as $payroll) {
      $results[] = $payroll->charge->base_wage;
    }

    return $results;
  }

  private function set_actual_data($payroll)
  {
    $this->insurance_company_id = $payroll->contract->insurance_company_id;
    $this->charge = $payroll->charge->name;
    $this->position = $payroll->position->name;
    $this->ovt->insurance_company_id = $payroll->contract->insurance_company->ovt_id;
    $this->position_group = $payroll->position_group->name;
    $this->position_group_id = $payroll->position_group->id;
    $this->employer_number = $payroll->position_group->company_address->city->employer_number->number;
    $this->employer_number_id = $payroll->position_group->company_address->city->employer_number->id;
  }

  private function calc_worked_moths($contracts, $year, $pay_date)
  {
    $results = [];
    foreach ($contracts as $i => $contract) {
      $results[] = $this->calc_months_days($contract, $year);
      $this->retirement_reason = $contract->retirement_reason ? $contract->retirement_reason->ovt_id : "";
    }

    $result = (object)[
      'start' => null,
      'end' => null,
      'months' => 0,
      'days' => 0
    ];

    $cuts = $this->calc_cuts($results);

    foreach ($cuts as $i => $cut) {
      if ($cut[0]->start->month < (Carbon::parse($pay_date)->month - 2)) {
        $months = array_sum(array_column($cut, 'months'));
        $days = array_sum(array_column($cut, 'days'));
        while ($days >= 30) {
          $days -= 30;
          $months++;
        }

        if ($months >= 3) {
          if (!$result->start) $result->start = reset($cut)->start;
          $result->end = end($cut)->end;
          $result->months += $months;
          $result->days += $days;
        }
      }
    }

    while ($result->days >= 30) {
      $result->days -= 30;
      $result->months++;
    }

    $this->start_date = $result->start ? $result->start->toDateString() : $result->start;
    $this->end_date = $result->end ? $result->end->toDateString() : $result->end;

    $this->start_date = Carbon::parse($this->start_date)->format('d/m/Y');
    $this->end_date = Carbon::parse($this->end_date)->format('d/m/Y');

    $this->worked_days = (object)[
      'months' => $result->months,
      'days' => $result->days,
    ];
  }

  private function calc_months_days($contract, $year)
  {
    $start = Carbon::parse($contract->start_date);
    if ($start->year < $year) {
      $start = Carbon::create($year, 1, 1);
    }
    if (!$contract->retirement_date && !$contract->end_date) {
      $end = Carbon::create($year, 12, 1)->endOfMonth()->startOfDay();
    } else {
      $end = $contract->retirement_date ? Carbon::parse($contract->retirement_date) : Carbon::parse($contract->end_date);
    }

    $months = $end->month - $start->month - 1;
    $last_day_of_month = Carbon::parse($end->format('Y-m-d'));
    $last_day_of_month = $last_day_of_month->endOfMonth()->startOfDay()->day;

    if ($last_day_of_month != 30 && $end->day == $last_day_of_month) {
      $days = (30 - $start->day + 1) + 30;
    } else {
      $days = (30 - $start->day + 1) + ($end->day);
    }
    while ($days >= 30) {
      $days -= 30;
      $months++;
    }

    return (object)[
      'start' => $start,
      'end' => (!$contract->retirement_date && !$contract->end_date) ? "" : $end,
      'months' => $months,
      'days' => $days
    ];
  }

  private function calc_cuts($contracts)
  {
    $cuts = [];
    $x = 0;

    foreach ($contracts as $i => $contract) {
      if ($i == 0) {
        $cuts[$x] = array();
        $cuts[$x][] = $contract;
      } else {
        if ($contracts[$i - 1]->end->addDays(1)->toDateString() == $contract->start->toDateString()) {
          $cuts[$x][] = $contract;
        } else {
          $x++;
          $cuts[$x] = array();
          $cuts[$x][] = $contract;
        }
      }
    }

    return $cuts;
  }

  public function generateImage($procedure)
  {
    $year_shortened = substr(strval($procedure->year), -2);
    $this->code = implode([$procedure->id, str_pad($this->employee_id + 1, 4, '0', STR_PAD_LEFT), $year_shortened], '-');
    $this->code_image = DNS2D::getBarcodePNG(($this->employee_id . ' ' . $this->full_name . ' ' . $this->position . ' ' . $procedure->id . ' ' . $this->year . ' ' . $this->average . ' ' . ($this->worked_days['months'] * 30 + $this->worked_days['days']) . ' ' . $this->bonus), "PDF417", 3, 33, array(1, 1, 1));
    return $this;
  }
}
