-- Back up the database, select it in phpMyAdmin, then run this migration once.
SET NAMES utf8mb4;

CREATE TABLE templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type ENUM('call_script','objection','whatsapp','email','faq') NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    tags VARCHAR(500) NOT NULL DEFAULT '',
    department VARCHAR(120) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_templates_published_type (is_published, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Illustrative examples only: review and customise before sharing with students.
INSERT INTO templates (type, title, body, tags, department, is_published) VALUES
('call_script','Example: First introduction','Hello {student_name}, I am {counselor_name} from DegreeDrishti. Is now a convenient time to discuss your education goals?','introduction,first call',NULL,1),
('call_script','Example: Understand goals','What would you like to achieve with {program}, {student_name}? What schedule would work best for you?','discovery,goals',NULL,1),
('call_script','Example: Discuss options','Let us review the available details for {program} at {university}. I will check the current information before advising you.','discovery,university',NULL,1),
('call_script','Example: Follow-up call','Hello {student_name}, this is {counselor_name}. I am following up on your questions about {program}. What else would help you decide?','follow-up,questions',NULL,1),
('call_script','Example: Next steps','Thank you, {student_name}. I will confirm the next steps for {program} and share the latest information with you.','next steps,follow-up',NULL,1),
('objection','Example: Need more time','Of course, {student_name}. Which information would be most useful while you consider {program}?','time,follow-up',NULL,1),
('objection','Example: Fee concern','I understand cost matters. Let me verify the current fees for {program} at {university} and discuss the available information.','fees,concerns',NULL,1),
('objection','Example: Unsure about schedule','Let us check the current schedule and learning format for {program} so you can assess whether it fits your routine.','schedule,concerns',NULL,1),
('objection','Example: Comparing universities','That makes sense, {student_name}. We can compare verified details for the options you are considering, including {university}.','comparison,university',NULL,1),
('objection','Example: Need family input','Take the time you need. I can share verified information about {program} for you to discuss with your family.','family,follow-up',NULL,1),
('whatsapp','Example: Initial message','Hello {student_name}, I am {counselor_name} from DegreeDrishti. May I share information about {program}?','introduction,message',NULL,1),
('whatsapp','Example: Follow-up message','Hello {student_name}, following up on your interest in {program}. Please let me know if you have any questions.','follow-up,message',NULL,1),
('whatsapp','Example: Share details','Hello {student_name}, I will send the verified details for {program} at {university} shortly.','details,university',NULL,1),
('whatsapp','Example: Schedule a call','Hello {student_name}, when would be a convenient time to discuss {program}? Regards, {counselor_name}.','schedule,call',NULL,1),
('whatsapp','Example: Thank you message','Thank you for speaking with me, {student_name}. Please contact me if you have questions about {program}. - {counselor_name}','thank you,follow-up',NULL,1),
('email','Example: Introduction email','Hello {student_name},\n\nI am {counselor_name} from DegreeDrishti. I would be happy to discuss your interest in {program}.\n\nRegards,\n{counselor_name}','introduction,email',NULL,1),
('email','Example: Programme follow-up','Hello {student_name},\n\nFollowing up on your interest in {program} at {university}. Please share any questions you would like us to check.\n\nRegards,\n{counselor_name}','follow-up,email',NULL,1),
('email','Example: Request a call','Hello {student_name},\n\nPlease let me know a convenient time to discuss your education goals and {program}.\n\nRegards,\n{counselor_name}','schedule,email',NULL,1),
('email','Example: Information update','Hello {student_name},\n\nI am checking the latest available information about {program} at {university} and will follow up with you.\n\nRegards,\n{counselor_name}','details,email',NULL,1),
('email','Example: Post-call thank you','Hello {student_name},\n\nThank you for your time today. Please let me know if you have additional questions about {program}.\n\nRegards,\n{counselor_name}','thank you,email',NULL,1),
('faq','Example: How can I check the fee?','Ask your counsellor to confirm the latest fee information for {program} at {university} before making a decision.','fees,information',NULL,1),
('faq','Example: How can I compare programmes?','List your goals and compare the latest verified programme details, schedule and fees with your counsellor.','comparison,program',NULL,1),
('faq','Example: Where can I confirm eligibility?','Ask your counsellor to check the current eligibility criteria for {program} with {university}.','eligibility,information',NULL,1),
('faq','Example: How do I ask a follow-up question?','Contact {counselor_name} with your question about {program}; they can check the latest information.','follow-up,contact',NULL,1),
('faq','Example: How can I check the next steps?','Your counsellor can explain the current next steps after verifying the details with {university}.','next steps,information',NULL,1);
