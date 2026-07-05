export type PersonalInfo = {
    first_name: string | null;
    last_name: string | null;
    phone_code: string | null;
    phone_number: string | null;
    location: string | null;
    headline: string | null;
    summary: string | null;
};

export type Experience = {
    company_name: string | null;
    job_title: string | null;
    employment_type: string | null;
    currently_working: boolean | null;
    start_month: string | null;
    start_year: string | null;
    end_month: string | null;
    end_year: string | null;
    description: string | null;
    location: string | null;
};

export type Education = {
    school_name: string | null;
    school_location: string | null;
    education_level: string | null;
    field_of_study: string | null;
    start_month: string | null;
    start_year: string | null;
    end_month: string | null;
    end_year: string | null;
    description: string | null;
};

export type ExperienceEducation = {
    experiences: Experience[];
    educations: Education[];
};

export type SkillsPortfolio = {
    skills: string[];
    portfolio_url: string | null;
    linkedin_url: string | null;
};

export type ParseCvData = {
    personal_info: PersonalInfo;
    experience_education: ExperienceEducation;
    skills_portfolio: SkillsPortfolio;
};

export type ParseCvResponse = {
    data: ParseCvData;
};

export type ParserStatusResponse = {
    available: boolean;
    warning: string | null;
};

export type ParseCvErrorResponse = {
    message: string;
    errors?: {
        cv?: string[];
    };
};
