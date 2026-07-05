<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Freelance = 'freelance';
    case Internship = 'internship';
    case Permanent = 'permanent';
    case CasualTemporary = 'casual_temporary';
}
