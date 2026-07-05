<?php

namespace App\Enums;

enum EducationLevel: string
{
    case HighSchool = 'high_school';
    case Certificate1 = 'certificate_1';
    case Certificate2 = 'certificate_2';
    case Certificate3 = 'certificate_3';
    case Certificate4 = 'certificate_4';
    case Diploma = 'diploma';
    case AssociateDegree = 'associate_degree';
    case Bachelor = 'bachelor';
    case GraduateDiploma = 'graduate_diploma';
    case Master = 'master';
    case Doctorate = 'doctorate';
}
