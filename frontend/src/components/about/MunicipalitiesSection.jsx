import { useEffect, useRef, useState } from 'react'
import { FiArrowRight } from 'react-icons/fi'
import { getMunicipalityProfile, getMunicipalityProfiles } from '../../api/municipalityProfiles'
import { getImageUrl } from '../../utils/helpers'
import Modal from '../common/Modal'
import LoadingSpinner from '../common/LoadingSpinner'
import Reveal from '../common/Reveal'
import SectionHeader from '../common/SectionHeader'
import MunicipalityProfileView from './MunicipalityProfileView'

/** "Municipality · Province of Sorsogon", or "City · …" for Sorsogon City. */
const lguLine = (town) => `${town.lgu_type === 'city' ? 'Component city' : 'Municipality'} · Province of Sorsogon`

/**
 * The About page's cards for the 14 municipalities and Sorsogon City. A card opens that town's
 * contact information, geography and history, as kept by the provincial Tourism Office.
 */
export default function MunicipalitiesSection() {
  const [towns, setTowns] = useState(null)
  const [selected, setSelected] = useState(null)
  const [profile, setProfile] = useState(null)
  const [failed, setFailed] = useState(false)
  // The town last clicked: an earlier, slower answer must not replace its profile.
  const requested = useRef(null)

  useEffect(() => {
    getMunicipalityProfiles()
      .then(setTowns)
      .catch(() => setTowns([]))
  }, [])

  const open = (town) => {
    setSelected(town)
    setProfile(null)
    setFailed(false)
    requested.current = town.id
    getMunicipalityProfile(town.id)
      .then((data) => requested.current === town.id && setProfile(data))
      .catch(() => requested.current === town.id && setFailed(true))
  }

  const close = () => {
    requested.current = null
    setSelected(null)
  }

  if (towns && towns.length === 0) return null

  return (
    <section className="pb-16 sm:pb-20" aria-labelledby="municipalities-heading">
      <div className="max-w-6xl mx-auto px-4 sm:px-6">
        <Reveal>
          <SectionHeader
            eyebrow="Municipalities"
            title={<span id="municipalities-heading">Discover the Municipalities of Sorsogon</span>}
            intro="Get to know the communities, geography, and history that make each part of Sorsogon unique."
          />
        </Reveal>

        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
          {towns === null
            ? Array.from({ length: 15 }, (_, i) => (
                <li key={i} aria-hidden="true">
                  <div className="skeleton aspect-4/5 rounded-2xl" />
                </li>
              ))
            : towns.map((town) => (
                <li key={town.id}>
                  <button
                    type="button"
                    onClick={() => open(town)}
                    aria-haspopup="dialog"
                    className="group relative block aspect-4/5 w-full overflow-hidden rounded-2xl bg-primary-900 text-left shadow-(--shadow-card) transition-shadow duration-300 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                  >
                    {town.photo && (
                      <img
                        src={getImageUrl(town.photo.thumbnail_path || town.photo.image_path)}
                        alt={town.photo.caption ? `${town.photo.caption}, ${town.name}` : town.name}
                        loading="lazy"
                        className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                      />
                    )}
                    <span className="absolute inset-0 bg-linear-to-t from-black/75 via-black/15 to-transparent" aria-hidden="true" />
                    <span className="absolute inset-x-0 bottom-0 p-3 sm:p-4">
                      {town.lgu_type === 'city' && (
                        <span className="mb-1 inline-block rounded-full bg-accent-400 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wider text-gray-900">City</span>
                      )}
                      <span className="flex items-center gap-1 font-heading text-base font-semibold leading-tight text-white sm:text-lg">
                        {town.name}
                        <FiArrowRight size={14} className="shrink-0 opacity-0 transition-all duration-300 group-hover:translate-x-0.5 group-hover:opacity-100" aria-hidden="true" />
                      </span>
                    </span>
                  </button>
                </li>
              ))}
        </ul>
      </div>

      {selected && (
        <Modal title={selected.name} subtitle={lguLine(selected)} size="lg" onClose={close}>
          {profile ? (
            <MunicipalityProfileView profile={profile} />
          ) : failed ? (
            <div className="py-8 text-center text-sm text-gray-600">
              <p>We couldn't load {selected.name}'s information.</p>
              <button type="button" onClick={() => open(selected)} className="btn-primary mt-4">Try again</button>
            </div>
          ) : (
            <LoadingSpinner />
          )}
        </Modal>
      )}
    </section>
  )
}
