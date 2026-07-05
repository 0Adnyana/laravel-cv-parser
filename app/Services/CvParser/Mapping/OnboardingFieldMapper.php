<?php

namespace App\Services\CvParser\Mapping;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Support\PhoneNormalizer;

class OnboardingFieldMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}
     */
    public function map(array $data): array
    {
        $flat = $this->flatten($data);

        $phone = PhoneNormalizer::splitForForm(
            is_string($flat['phone'] ?? null) ? $flat['phone'] : null,
            'AU',
        );

        return [
            'personal_info' => [
                'first_name' => $this->nullableString($flat['first_name'] ?? null),
                'last_name' => $this->nullableString($flat['last_name'] ?? null),
                'phone_code' => $phone['phone_code'],
                'phone_number' => $phone['phone_number'],
                'location' => $this->nullableString($flat['location'] ?? null),
                'headline' => $this->nullableString($flat['headline'] ?? null),
                'summary' => $this->nullableString($flat['summary'] ?? null),
            ],
            'experience_education' => [
                'experiences' => $this->mapExperiences($flat['experiences'] ?? []),
                'educations' => $this->mapEducations($flat['educations'] ?? []),
            ],
            'skills_portfolio' => [
                'skills' => $this->mapSkills($flat['skills'] ?? []),
                'portfolio_url' => $this->normalizeUrl($flat['portfolio_url'] ?? null),
                'linkedin_url' => $this->normalizeUrl($flat['linkedin_url'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data): array
    {
        if (isset($data['personal_info']) || isset($data['experience_education']) || isset($data['skills_portfolio'])) {
            $personal = is_array($data['personal_info'] ?? null) ? $data['personal_info'] : [];
            $experienceEducation = is_array($data['experience_education'] ?? null) ? $data['experience_education'] : [];
            $skillsPortfolio = is_array($data['skills_portfolio'] ?? null) ? $data['skills_portfolio'] : [];

            return array_merge(
                $personal,
                $experienceEducation,
                $skillsPortfolio,
            );
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapExperiences(mixed $experiences): array
    {
        if (! is_array($experiences)) {
            return [];
        }

        return array_values(array_map(function (mixed $experience): array {
            $experience = is_array($experience) ? $experience : [];

            return [
                'company_name' => $this->nullableString($experience['company_name'] ?? null),
                'job_title' => $this->nullableString($experience['job_title'] ?? null),
                'employment_type' => $this->normalizeEmploymentType($experience['employment_type'] ?? null),
                'currently_working' => $experience['currently_working'] ?? null,
                'start_month' => $this->nullableString($experience['start_month'] ?? null),
                'start_year' => $this->nullableString($experience['start_year'] ?? null),
                'end_month' => $this->nullableString($experience['end_month'] ?? null),
                'end_year' => $this->nullableString($experience['end_year'] ?? null),
                'description' => $this->nullableString($experience['description'] ?? null),
                'location' => $this->nullableString($experience['location'] ?? null),
            ];
        }, $experiences));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapEducations(mixed $educations): array
    {
        if (! is_array($educations)) {
            return [];
        }

        return array_values(array_map(function (mixed $education): array {
            $education = is_array($education) ? $education : [];

            return [
                'school_name' => $this->nullableString($education['school_name'] ?? null),
                'school_location' => $this->nullableString($education['school_location'] ?? null),
                'education_level' => $this->normalizeEducationLevel($education['education_level'] ?? null),
                'field_of_study' => $this->nullableString($education['field_of_study'] ?? null),
                'start_month' => $this->nullableString($education['start_month'] ?? null),
                'start_year' => $this->nullableString($education['start_year'] ?? null),
                'end_month' => $this->nullableString($education['end_month'] ?? null),
                'end_year' => $this->nullableString($education['end_year'] ?? null),
                'description' => $this->nullableString($education['description'] ?? null),
            ];
        }, $educations));
    }

    /**
     * @return list<string>
     */
    private function mapSkills(mixed $skills): array
    {
        if (! is_array($skills)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $skill): ?string => is_string($skill) ? trim($skill) : null, $skills),
            fn (?string $skill): bool => $skill !== null && $skill !== '',
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        foreach (EmploymentType::cases() as $type) {
            if ($type->value === $normalized) {
                return $type->value;
            }
        }

        return null;
    }

    private function normalizeEducationLevel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['phd', 'associate'], true)) {
            return null;
        }

        foreach (EducationLevel::cases() as $level) {
            if ($level->value === $normalized) {
                return $level->value;
            }
        }

        return null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https://'.ltrim($trimmed, '/');
        }

        if (! preg_match('#^https?://#i', $trimmed)) {
            return 'https://'.$trimmed;
        }

        return $trimmed;
    }
}
