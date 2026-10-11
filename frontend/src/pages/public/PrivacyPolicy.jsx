import Reveal from '../../components/common/Reveal'

const SECTIONS = [
  {
    title: 'Introduction',
    body: [
      'This Privacy Policy explains how the Sorsogon Tourism Information Management system ("STIM"), operated by the Tourism Office of Bulan, Sorsogon, collects, uses, and protects information from tourists, business owners, and administrators who use this platform.',
      'By creating an account or submitting information through STIM, you agree to the collection and use of information as described in this policy.',
    ],
  },
  {
    title: 'Information We Collect',
    body: [
      'We collect information you provide directly to us, including:',
    ],
    list: [
      'Account information — name, email address, phone number, and password.',
      'Business submission details — business name, type, address, municipality and barangay, contact number, operating hours, business permit number, and owner identification for business owner applications.',
      'Uploaded files — business permit documents and images of accommodations, food establishments, or products submitted for listing.',
      'Itinerary planner inputs — your travel preferences, trip duration, and selected destinations, used to generate personalized itineraries.',
      'Basic usage information such as pages visited, used to help the Tourism Office understand how the platform is used.',
    ],
  },
  {
    title: 'How We Use Your Information',
    body: [
      'We use the information we collect to create and authenticate your account, display approved business listings to the public, generate itinerary recommendations, allow Tourism Office administrators to review and approve business submissions, and send you transactional emails such as submission status updates and password reset links.',
    ],
  },
  {
    title: 'Cookies and Session Data',
    body: [
      'STIM uses token-based authentication (via Laravel Sanctum) rather than traditional tracking cookies. When you log in, a session token is stored in your browser to keep you signed in. We do not use third-party advertising trackers.',
    ],
  },
  {
    title: 'How We Share Your Information',
    body: [
      'Business submission details are shared with Tourism Office administrators for review and approval. Once a business listing is approved, its public-facing details (name, description, location, contact information, images) become visible to all visitors of the platform.',
      'We do not sell your personal information to third parties. Information may be disclosed if required by law or to protect the rights, property, or safety of STIM, its users, or the public.',
    ],
  },
  {
    title: 'Data Retention',
    body: [
      'Account information is retained for as long as your account remains active. Business permit and verification documents are retained for municipal record-keeping purposes as part of the tourism business registry. You may request removal of your account and associated data by contacting the Tourism Office.',
    ],
  },
  {
    title: 'Data Security',
    body: [
      'Passwords are stored using industry-standard hashing and are never stored in plain text. Access to business submission reviews is restricted to authorized Tourism Office administrators. While we take reasonable measures to protect your data, no online system can guarantee absolute security.',
    ],
  },
  {
    title: 'Your Rights and Choices',
    body: [
      'You may update your profile information at any time while logged in, reset your password if forgotten, and request that your account and associated data be deleted by contacting the Tourism Office directly.',
    ],
  },
  {
    title: "Children's Privacy",
    body: [
      'STIM is not directed at children under the age of 13, and we do not knowingly collect personal information from children.',
    ],
  },
  {
    title: 'Changes to This Policy',
    body: [
      'We may update this Privacy Policy from time to time. Changes will be reflected by the "Last updated" date at the top of this page.',
    ],
  },
  {
    title: 'Contact Us',
    body: [
      'If you have questions about this Privacy Policy or how your data is handled, please contact:',
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
      'This Privacy Policy is a general-purpose placeholder written to reflect what STIM actually does. It has not been reviewed by legal counsel and should be replaced with a professionally reviewed policy before relying on it in a production or real-world deployment.',
    ],
  },
]

export default function PrivacyPolicy() {
  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 pt-16 pb-4">
        <h1 className="text-3xl font-heading font-bold text-gray-900 mb-2">Privacy Policy</h1>
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
