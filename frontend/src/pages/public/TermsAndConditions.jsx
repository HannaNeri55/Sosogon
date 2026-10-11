import Reveal from '../../components/common/Reveal'

const SECTIONS = [
  {
    title: 'Acceptance of Terms',
    body: [
      'By accessing or using the Sorsogon Tourism Information Management system ("STIM"), you agree to be bound by these Terms and Conditions. If you do not agree to these terms, please do not use the platform.',
    ],
  },
  {
    title: 'Description of the Service',
    body: [
      'STIM is a web-based tourism information and management platform for the Province of Sorsogon, with Bulan as the pilot implementation area. It allows tourists to browse verified destinations, accommodations, food and beverage establishments, retail shops, and events, and to generate AI-assisted travel itineraries. It also allows business owners to submit and manage listings for admin review.',
    ],
  },
  {
    title: 'User Accounts and Eligibility',
    body: [
      'STIM supports two public account types: tourist accounts, which can be used immediately after registration, and business owner accounts, which require Tourism Office approval before login is enabled.',
      'You agree to provide accurate, current, and complete information when creating an account or submitting a business listing, and you are responsible for maintaining the confidentiality of your account credentials.',
    ],
  },
  {
    title: 'Business Owner Submissions and Admin Review',
    body: [
      'Business listing submissions — including business details, location, contact information, and uploaded business permit documents — are subject to review by Tourism Office administrators. Submissions remain in a "pending" state until approved, and administrators reserve the right to reject or later remove listings that do not comply with these terms or applicable regulations.',
      'If a rejected or previously approved submission is edited, it will be returned to "pending" status and must go through admin approval again before it (or its changes) become publicly visible.',
    ],
  },
  {
    title: 'Acceptable Use',
    body: [
      'You agree not to submit false, misleading, or fraudulent business or permit information, misuse the platform to harm other users or the Tourism Office, or attempt to gain unauthorized access to accounts, data, or systems that are part of STIM.',
    ],
  },
  {
    title: 'Uploaded Content and Intellectual Property',
    body: [
      'You retain ownership of images, documents, and other content you upload. By uploading content, you grant STIM a license to display that content on the platform for the purpose of operating and promoting tourism listings.',
    ],
  },
  {
    title: 'Itinerary Planner Disclaimer',
    body: [
      'The AI-based itinerary planner generates suggested trip plans based on your stated preferences and available listing data. These suggestions are provided for planning convenience only and are not a guarantee of accuracy, availability, pricing, or business operating hours at the time of your visit.',
    ],
  },
  {
    title: 'Listing Accuracy Disclaimer',
    body: [
      'Business listing information (accommodations, food and beverage, retail shops) is submitted by third-party business owners. While the Tourism Office reviews submissions before approval, STIM does not guarantee the continued accuracy, completeness, or availability of listing information at any given time.',
    ],
  },
  {
    title: 'Account Suspension and Termination',
    body: [
      'Accounts found to be submitting fraudulent information, violating acceptable use, or otherwise misusing the platform may be suspended or terminated by an administrator. Suspended accounts lose access to platform features until reinstated.',
    ],
  },
  {
    title: 'Limitation of Liability',
    body: [
      'STIM and the Tourism Office are not liable for any indirect, incidental, or consequential damages arising from your use of the platform, including reliance on itinerary suggestions or third-party listing information.',
    ],
  },
  {
    title: 'Governing Law',
    body: [
      'These Terms and Conditions are governed by the laws of the Republic of the Philippines, without regard to conflict of law principles, and are intended to apply within the context of the Province of Sorsogon.',
    ],
  },
  {
    title: 'Changes to These Terms',
    body: [
      'We may update these Terms and Conditions from time to time. Continued use of STIM after changes are posted constitutes acceptance of the revised terms.',
    ],
  },
  {
    title: 'Contact Us',
    body: [
      'If you have questions about these Terms and Conditions, please contact:',
    ],
    list: [
      'Tourism Office of Bulan',
      'Bulan, Sorsogon, Philippines',
      'tourism@bulan.gov.ph',
    ],
  },
  {
    title: 'A Note on This Document',
    body: [
      'These Terms and Conditions are a general-purpose placeholder written to reflect what STIM actually does. They have not been reviewed by legal counsel and should be replaced with a professionally reviewed version before relying on them in a production or real-world deployment.',
    ],
  },
]

export default function TermsAndConditions() {
  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 pt-16 pb-4">
        <h1 className="text-3xl font-heading font-bold text-gray-900 mb-2">Terms &amp; Conditions</h1>
        <p className="text-sm text-gray-500">Last updated: September 23, 2026</p>
      </div>

      <div className="max-w-3xl mx-auto px-4 sm:px-6 pb-16 space-y-6">
        {SECTIONS.map((section, i) => (
          <Reveal key={section.title} className="bg-white rounded-xl p-6 sm:p-8 border border-gray-100" delay={Math.min(i * 40, 300)}>
            <h2 className="text-xl font-heading font-semibold text-gray-900 mb-3">{section.title}</h2>
            {section.body.map((paragraph) => (
              <p key={paragraph} className="text-gray-600 leading-relaxed mb-3 last:mb-0">
                {paragraph}
              </p>
            ))}
            {section.list && (
              <ul className="list-disc pl-5 text-gray-600 space-y-1.5 mt-3">
                {section.list.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            )}
          </Reveal>
        ))}
      </div>
    </div>
  )
}
