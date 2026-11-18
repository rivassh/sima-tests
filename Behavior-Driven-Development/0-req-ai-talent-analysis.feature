# 0.req — AI Talent Analysis System (Naveed Sharifi's Initial Request)
#
# As: Naveed Sharifi (Evaluator / Product Owner)
# I want: An intelligent talent analysis and management system
# So that: The system can collect job opportunities and resumes from JobVision,
#           extract key candidate information using NLP, enrich from public sources,
#           create comprehensive profiles, and evaluate candidate-job fit using AI

Feature: AI Talent Analysis and Management System (0.req)

  Background:
    Given the system is configured with JobVision API credentials
    And the AI adapter (mimo-adapter) is available at the configured endpoint
    And the candidate database is initialized with required tables

  @crawler @jobvision @collection
  Scenario: System collects job posts from JobVision API
    Given the JobVision crawler is scheduled to run
    When the crawler fetches job posts for "Mechanical Engineer" positions
    Then job posts are stored in the ats_job_posts table
    And each job post contains: external_id, source, title, company, location, description, skills, experience_years, employment_type, status
    And the job post count reflects the JobVision API response

  @crawler @jobvision @collection
  Scenario: System collects candidate resumes from JobVision API
    Given the JobVision crawler is scheduled to run
    When the crawler fetches candidate resumes for active job posts
    Then candidates are stored in the candidates table
    And each candidate contains: candidate_id, personal_info, skills, work_history, education_history, public_profiles, resume_text
    And candidate data matches the JobVision API response contract

  @nlp @extraction @skills
  Scenario: System extracts skills from candidate resume text using NLP
    Given a candidate with resume_text containing "SOLIDWORKS, PDMS, CAESAR, Python, SQL"
    When the NLP extraction pipeline processes the candidate
    Then skills are extracted and normalized to standard taxonomy
    And the extracted skills include: SOLIDWORKS, PDMS, CAESAR, Python, SQL

  @nlp @extraction @experience
  Scenario: System extracts work history from candidate resume text using NLP
    Given a candidate with resume_text containing "Senior HPHT Piping Engineer ... 6 years"
    When the NLP extraction pipeline processes the candidate
    Then work history is extracted with: title, company, start_date, end_date, duration_years, description
    And the extracted work history shows 6 years at the company

  @nlp @extraction @education
  Scenario: System extracts education from candidate resume text using NLP
    Given a candidate with resume_text containing "Master's in Mechanical Engineering from Sharif University 1394-1397"
    When the NLP extraction pipeline processes the candidate
    Then education history is extracted with: degree, institution, field_of_study, start_date, end_date, graduation_date
    And the extracted education shows Master's in Mechanical Engineering from Sharif University

  @ai @evaluation @fit-scoring
  Scenario: AI evaluates candidate fit against a job post using all 7 criteria from 0-2.req matrix
    Given a Mechanical Engineer job post with requirements matching 0-2.req
    And a candidate profile that satisfies all 7 criteria
    When the AI evaluation service scores the candidate
    Then the evaluation returns a fit_score >= 0.85
    And the breakdown shows all 7 criteria met

  @ai @evaluation @fit-scoring
  Scenario: AI evaluates candidate fit with partial criteria match
    Given a Mechanical Engineer job post with requirements matching 0-2.req
    And a candidate who meets 4 of 7 criteria
    When the AI evaluation service scores the candidate
    Then the evaluation returns a fit_score between 0.4 and 0.7
    And the breakdown clearly shows which criteria are met and which are missing

  @ai @evaluation @fit-scoring
  Scenario: AI evaluation handles candidate with no relevant experience
    Given a Mechanical Engineer job post with HPHT requirements
    And a candidate with Bachelor's in Computer Science, 2 years web development
    When the AI evaluation service scores the candidate
    Then the evaluation returns a fit_score <= 0.2
    And the system correctly identifies this as a non-qualifying candidate

  @e2e @pipeline @full-flow
  Scenario: Complete pipeline from JobVision crawl to AI fit evaluation
    Given the system starts with empty job_posts and candidates tables
    When the full pipeline executes:
      | Step | Action |
      | 1 | Crawl job posts from JobVision |
      | 2 | Crawl candidates for those job posts |
      | 3 | Extract NLP information from resumes |
      | 4 | Enrich from LinkedIn (where available) |
      | 5 | Create comprehensive profiles |
      | 6 | Evaluate candidate-job fit using AI |
    Then job posts are populated in ats_job_posts
    And candidates are populated in candidates table
    And comprehensive profiles exist in candidate_profiles
    And AI evaluations exist in screening_results or ats_applications
    And the pipeline completes without errors
    And the final output includes ranked candidates per job post
