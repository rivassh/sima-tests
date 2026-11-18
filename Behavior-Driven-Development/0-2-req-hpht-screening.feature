# 0-2.req — Mechanical Engineering HPHT Screening (Kroobi)
#
# As: Kroobi (Evaluator)
# I want to screen candidates for a mechanical engineering role with high-pressure systems (HPHT) responsibilities,
# So that the system identifies the candidates that actually qualify:
#
#   - Bachelor minimum in mechanical engineering (design, manufacturing, energy conversion)
#   - 4+ years documented practical experience in design, construction, machining or piping of HPHT systems, oil & gas, reactors, high-pressure hydraulics
#   - Complete mastery of SolidWorks or CATIA (3D modelling, manufacturing drawings, stress/FEA simulation)
#   - High pressure systems: mastery of piping, connections, valves at 500-700 bar (10000 psi)
#   - Metallurgy and sealing under high temp/pressure (polymer, elastomer, spring seals)
#   - Precision machining (CNC), boring, honing, dimensional QC, interaction with fabricators
#   - Enterprising, energetic, capable in field assembly and equipment testing

Feature: Mechanical Engineering HPHT Candidate Screening (0-2.req)

  Background:
    Given the system is configured with the 0-2-matrix.json evaluation criteria
    And candidates in the database have been crawled from JobVision
    And the GenericScreeningEngine is operational

  @screening @education
  Scenario: A candidate without mechanical engineering education is rejected
    Given a candidate with "Computer Science" degree only
    When the screening engine evaluates the candidate against Mechanical Engineer requirements
    Then the screening result indicates education criterion NOT met
    And the candidate is classified as not qualifying

  @screening @education
  Scenario: A candidate with insufficient years of education is rejected
    Given a candidate with "Associate Degree in Electronics" and 2 years experience
    When the screening engine evaluates the candidate against Mechanical Engineer requirements
    Then the screening result indicates education criterion NOT met
    And the candidate is classified as not qualifying

  @screening @experience @education
  Scenario: A candidate who meets education but has no documented HPHT experience is rejected
    Given a candidate with Bachelor's in Mechanical Engineering but zero HPHT experience
    When the screening engine evaluates the candidate against Mechanical Engineer requirements
    Then the screening result indicates experience criterion NOT met
    And the candidate is classified as not qualifying

  @screening @experience
  Scenario: A candidate with less than 4 years documented HPHT experience is rejected
    Given a candidate with Bachelor's in ME, SolidWorks skill, 3 years HPHT experience
    When the screening engine evaluates the candidate
    Then the screening result indicates experience criterion NOT met (requires 4+ years)
    And the candidate is classified as not qualifying

  @screening @experience
  Scenario: A candidate with exactly 4 years HPHT experience PASSES
    Given a candidate with Bachelor's in ME, SolidWorks skill, 4.33 years HPHT experience
    When the screening engine evaluates the candidate
    Then the screening result indicates experience criterion MET
    And the candidate qualifies for the shortlist

  @screening @software
  Scenario: Mastery of SolidWorks qualifies a candidate
    Given a candidate with "SolidWorks" listed as a top skill
    When the screening engine evaluates the candidate
    Then the design_software criterion is marked MET
    And the candidate is eligible

  @screening @software
  Scenario: Mastery of CATIA also qualifies a candidate
    Given a candidate with "CATIA" listed as a top skill
    When the screening engine evaluates the candidate
    Then the design_software criterion is marked MET
    And the candidate is eligible

  @screening @software
  Scenario: Candidate with neither SolidWorks nor CATIA is rejected on software criterion
    Given a candidate with "AutoCAD" only and no SolidWorks or CATIA
    When the screening engine evaluates the candidate
    Then the design_software criterion is NOT met
    And the candidate is rejected

  @screening @pressure
  Scenario: Candidate with piping/valve skills at HPHT pressure passes
    Given a candidate with "پایپینگ سیستم‌های فشار بالا", "شیرآلات فشار بالا", and "SolidWorks"
    When the screening engine evaluates the candidate
    Then the pressure_systems criterion is marked MET
    And the candidate qualifies

  @screening @pressure
  Scenario: Candidate with design software but no pressure knowledge is partially qualified
    Given a candidate with SolidWorks but no piping/valve experience
    When the screening engine evaluates the candidate
    Then the pressure_systems criterion is NOT met
    And the candidate is partially qualified but should not make the shortlist alone

  @screening @metallurgy
  Scenario: Candidate with metallurgy/sealing knowledge passes
    Given a candidate with "متالورژی و آب‌بندی" and "سیل‌های پلیمری" in resume
    When the screening engine evaluates the candidate
    Then the metallurgy criterion is marked MET
    And the candidate is eligible

  @screening @manufacturing
  Scenario: Candidate with CNC machining skills passes
    Given a candidate with "CNC, بورینگ, هونینگ, کنترل کیفی ابعادی" in resume
    When the screening engine evaluates the candidate
    Then the manufacturing criterion is marked MET
    And the candidate is eligible

  @screening @personal
  Scenario: Candidate with personal trait keywords passes
    Given a candidate whose self_description contains "روحیه عمل‌گرایی, پرانرژی, توانمند"
    When the screening engine evaluates the candidate
    Then the personal_traits criterion is marked MET
    And the candidate is eligible

  @screening @shortlist
  Scenario: System generates a ranked shortlist of top N candidates from all screened applications
    Given multiple candidates are screened against the same Mechanical Engineer job post
    When the screening engine produces shortlist results
    Then the system returns a ranked list of qualifying candidates ordered by match_score
    And the shortlist contains exactly the candidates meeting ALL 7 criteria
    And the number of shortlisted candidates matches the configured limit (3-5)

  @screening @shortlist
  Scenario: Shortlist prioritizes candidates with stronger overall match
    Given two candidates both meeting all 7 criteria, but one has higher match_score
    When the screening engine ranks candidates
    Then the higher score candidate appears first in the shortlist
    And the match_score field reflects the weighted criteria evaluation

  @screening @matrix
  Scenario: ScreenJobPost uses the 0-2-matrix.json requirements extraction
    Given a job post with requirements text containing HPHT mechanical engineering
    When ScreeningOrchestrator.screenJobPost() is called
    Then the extracted requirements_matrix matches the 7 criteria from 0-2-matrix.json
    And criteria include: education, experience, design_software, pressure_systems, metallurgy, manufacturing, personal_traits
    And weights are preserved: 1.0, 1.0, 0.9, 1.0, 0.8, 0.8, 0.6

  @screening @matrix
  Scenario: Matrix extraction fails gracefully on malformed job description
    Given a job post with description missing critical keywords
    When ScreeningOrchestrator extracts the matrix
    Then an exception is thrown with a clear error message
    And the system does not crash; it records the error for debugging

  @e2e @pipeline @full-flow
  Scenario: Complete screening pipeline from crawl to shortlist
    Given the crawler has populated job posts and candidates
    When the ScreeningOrchestrator runs for the Mechanical Engineer - High Pressure Systems job post
    Then screening results are persisted to ats_applications with screening_score, screening_result, screened_at
    And filtered results exclude status='draft' or incomplete candidates
    And screened_at timestamp is recorded
    And the shortlist is generated with rank and match_score per shortlist item
    And all screening data is consistent with the 0-2.req evaluation matrix
