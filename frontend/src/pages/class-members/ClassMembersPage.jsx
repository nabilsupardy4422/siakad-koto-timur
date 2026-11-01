import {
    useCallback,
    useEffect,
    useMemo,
    useState,
  } from 'react'
  
  import { useAuth } from '../../context/useAuth'
  
  import PageHeader from '../../components/ui/PageHeader'
  import Card from '../../components/ui/Card'
  import Table from '../../components/ui/Table'
  import Select from '../../components/ui/Select'
  import Input from '../../components/ui/Input'
  import Button from '../../components/ui/Button'
  import Badge from '../../components/ui/Badge'
  import EmptyState from '../../components/ui/EmptyState'
  import ErrorState from '../../components/ui/ErrorState'
  import LoadingState from '../../components/ui/LoadingState'
  import Modal from '../../components/ui/Modal'
  
  import { classApi } from '../../services/classApi'
  import { classMemberApi } from '../../services/classMemberApi'
  import { studentApi } from '../../services/studentApi'
  import { academicYearApi } from '../../services/academicYearApi'
  
  import './ClassMembersPage.css'
  
  const columns = [
    { key: 'identity', label: 'Identitas Siswa' },
    { key: 'nisn', label: 'NISN' },
    { key: 'nis', label: 'NIS' },
    { key: 'tanggal_mulai', label: 'Tanggal Mulai' },
    { key: 'actions', label: 'Aksi' },
  ]
  
  function yearLabel(year) {
    return `${year.tahun_mulai}/${year.tahun_selesai} - ${year.semester}`
  }
  
  function formatDate(value) {
    if (!value) return '-'
  
    const parts = String(value).slice(0, 10).split('-')
  
    if (parts.length !== 3) return value
  
    return `${parts[2]}-${parts[1]}-${parts[0]}`
  }
  
  function studentName(student) {
    return student?.nama_lengkap || student?.user?.name || '-'
  }
  
  function errorMessage(error, fallback) {
    return (
      error?.response?.data?.message ||
      error?.data?.message ||
      error?.message ||
      fallback
    )
  }
  
  function getRoleCode(role) {
    return typeof role === 'string'
      ? role
      : role?.code || role?.name || null
  }
  
  function getSelectValue(value) {
    if (value?.target) {
      return String(value.target.value ?? '')
    }
  
    if (value && typeof value === 'object' && 'value' in value) {
      return String(value.value ?? '')
    }
  
    return String(value ?? '')
  }
  
  export default function ClassMembersPage() {
    const { role, assignments = [] } = useAuth()
  
    const roleCode = getRoleCode(role)
    const isTU = roleCode === 'TU'
    const isGuru = roleCode === 'GURU'
  
    const homeroomAssignments = useMemo(
      () =>
        assignments.filter(
          (assignment) => assignment.type === 'WALI_KELAS',
        ),
      [assignments],
    )
  
    const assignedClassIds = useMemo(
      () =>
        homeroomAssignments
          .map((assignment) => String(assignment.kelas_id))
          .filter((id) => id !== 'undefined' && id !== 'null'),
      [homeroomAssignments],
    )
  
    const [years, setYears] = useState([])
    const [classes, setClasses] = useState([])
    const [students, setStudents] = useState([])
    const [members, setMembers] = useState([])
  
    const [yearId, setYearId] = useState('')
    const [classId, setClassId] = useState('')
    const [studentId, setStudentId] = useState('')
    const [startDate, setStartDate] = useState(
      () => new Date().toISOString().slice(0, 10),
    )
  
    const [loadingOptions, setLoadingOptions] = useState(true)
    const [loadingMembers, setLoadingMembers] = useState(false)
    const [saving, setSaving] = useState(false)
    const [endingId, setEndingId] = useState(null)
    const [endingTarget, setEndingTarget] = useState(null)

    const [listError, setListError] = useState(null)
    const [formError, setFormError] = useState(null)
    const [successMessage, setSuccessMessage] = useState('')
    const [modalOpen, setModalOpen] = useState(false)
  
    const canManage =
      isTU || (isGuru && homeroomAssignments.length > 0)
  
    const selectedClass = useMemo(
      () =>
        classes.find(
          (item) => String(item.id) === String(classId),
        ) || null,
      [classes, classId],
    )
  
    const selectedYear = useMemo(
      () =>
        years.find(
          (year) =>
            String(year.id) ===
            String(selectedClass?.tahun_akademik_id),
        ) || null,
      [years, selectedClass],
    )
  
    const loadOptions = useCallback(async () => {
      setLoadingOptions(true)
      setListError(null)
  
      try {
        const [yearResponse, classResponse, studentResponse] =
          await Promise.all([
            academicYearApi.getAll({ per_page: 100 }),
            classApi.getAll({ per_page: 100 }),
            studentApi.getAll({ page: 1, per_page: 100 }),
          ])
  
        const allYears = Array.isArray(yearResponse?.data)
          ? yearResponse.data
          : []
  
        let allClasses = Array.isArray(classResponse?.data)
          ? classResponse.data
          : []
  
        const allStudents = Array.isArray(studentResponse?.data)
          ? studentResponse.data
          : []
  
        if (isGuru) {
          allClasses = allClasses.filter((item) =>
            assignedClassIds.includes(String(item.id)),
          )
        }
  
        setYears(allYears)
        setClasses(allClasses)
        setStudents(allStudents)
  
        if (allClasses.length > 0) {
          const currentClass = allClasses.find(
            (item) => String(item.id) === String(classId),
          )
  
          const nextClass = currentClass || allClasses[0]
  
          setClassId(String(nextClass.id))
          setYearId(String(nextClass.tahun_akademik_id))
        } else {
          setClassId('')
          setYearId('')
        }
      } catch (error) {
        setListError(error)
      } finally {
        setLoadingOptions(false)
      }
    }, [isGuru, assignedClassIds, classId])
  
    const loadMembers = useCallback(async () => {
      if (!classId || !/^\d+$/.test(String(classId))) {
        setMembers([])
        setLoadingMembers(false)
        return
      }
  
      setLoadingMembers(true)
      setListError(null)
  
      try {
        const response = await classMemberApi.getAll(classId, {
          per_page: 100,
        })
  
        setMembers(
          Array.isArray(response?.data) ? response.data : [],
        )
      } catch (error) {
        setMembers([])
        setListError(error)
      } finally {
        setLoadingMembers(false)
      }
    }, [classId])
  
    useEffect(() => {
      loadOptions()
    }, [loadOptions])
  
    useEffect(() => {
      loadMembers()
    }, [loadMembers])
  
    const visibleClasses = useMemo(
      () =>
        classes.filter(
          (item) =>
            !yearId ||
            String(item.tahun_akademik_id) === String(yearId),
        ),
      [classes, yearId],
    )
  
    const memberIds = useMemo(
      () => new Set(members.map((member) => String(member.siswa_id))),
      [members],
    )
  
    const availableStudents = useMemo(
      () =>
        students.filter(
          (student) => !memberIds.has(String(student.id)),
        ),
      [students, memberIds],
    )
  
    const yearOptions = useMemo(
      () =>
        years.map((year) => ({
          value: String(year.id),
          label: yearLabel(year),
        })),
      [years],
    )
  
    const classOptions = useMemo(
      () =>
        visibleClasses.map((item) => ({
          value: String(item.id),
          label: `${item.nama} — Tingkat ${item.tingkat}`,
        })),
      [visibleClasses],
    )
  
    const studentOptions = useMemo(
      () =>
        availableStudents.map((student) => ({
          value: String(student.id),
          label: `${studentName(student)} (${student.nisn || student.nis || student.id})`,
        })),
      [availableStudents],
    )
  
    function handleYearChange(rawValue) {
      const value = getSelectValue(rawValue)
  
      setYearId(value)
      setSuccessMessage('')
      setListError(null)
  
      const nextClass = classes.find(
        (item) =>
          String(item.tahun_akademik_id) === value &&
          (!isGuru || assignedClassIds.includes(String(item.id))),
      )
  
      setClassId(nextClass ? String(nextClass.id) : '')
    }
  
    function handleClassChange(rawValue) {
      const value = getSelectValue(rawValue)
  
      if (value && !/^\d+$/.test(value)) {
        setListError({
          message: 'Pilihan kelas tidak valid. Pilih kelas dari daftar.',
        })
        return
      }
  
      setClassId(value)
      setSuccessMessage('')
      setListError(null)
  
      const nextClass = classes.find(
        (item) => String(item.id) === value,
      )
  
      if (nextClass) {
        setYearId(String(nextClass.tahun_akademik_id))
      }
    }
  
    async function handleAddMember(event) {
      event.preventDefault()
  
      setFormError(null)
      setSuccessMessage('')
  
      if (
        !classId ||
        !/^\d+$/.test(String(classId)) ||
        !studentId ||
        !/^\d+$/.test(String(studentId)) ||
        !startDate
      ) {
        setFormError({
          message: 'Kelas, siswa, dan tanggal mulai wajib diisi dengan benar.',
        })
        return
      }
  
      setSaving(true)
  
      try {
        await classMemberApi.create(Number(classId), {
          siswa_id: Number(studentId),
          tanggal_mulai: startDate,
        })
  
        setModalOpen(false)
        setStudentId('')
        setSuccessMessage('Siswa berhasil ditambahkan ke kelas.')
  
        await loadMembers()
      } catch (error) {
        setFormError(error)
      } finally {
        setSaving(false)
      }
    }
  
    function openEndMembershipModal(member) {
      setSuccessMessage('')
      setFormError(null)
      setEndingTarget(member)
    }

    function closeEndMembershipModal() {
      if (endingId !== null) return
      setEndingTarget(null)
    }

    async function handleEndMembership() {
      if (!endingTarget || endingId !== null) return

      const member = endingTarget
      const name = studentName(member.siswa)

      setEndingId(member.siswa_id)
      setSuccessMessage('')
      setListError(null)

      try {
        await classMemberApi.endMembership(
          Number(classId),
          Number(member.siswa_id),
        )

        setEndingTarget(null)
        setSuccessMessage(`Keanggotaan ${name} berhasil diakhiri.`)
        await loadMembers()
      } catch (error) {
        setListError(error)
      } finally {
        setEndingId(null)
      }
    }
  
    if (!isTU && !isGuru) {
      return (
        <section className="class-members-page">
          <PageHeader
            title="Anggota Kelas"
            description="Kelola keanggotaan siswa dalam kelas."
          />
  
          <ErrorState message="Kamu tidak memiliki akses ke halaman ini." />
        </section>
      )
    }
  
    return (
      <section className="class-members-page">
        <PageHeader
          title="Anggota Kelas"
          description="Kelola penempatan siswa pada rombongan belajar dan tahun akademik."
          actions={
            canManage ? (
              <Button
                disabled={!classId || loadingOptions}
                onClick={() => {
                  setStudentId('')
                  setFormError(null)
                  setModalOpen(true)
                }}
              >
                + Tambah Anggota
              </Button>
            ) : null
          }
        />
  
        {successMessage && (
          <div
            className="class-members-page__success"
            role="status"
          >
            {successMessage}
          </div>
        )}
  
        <Card className="class-members-page__card">
          <div className="class-members-page__filters">
            <Select
              id="member-year"
              name="year"
              label="Tahun Akademik"
              value={yearId}
              options={yearOptions}
              placeholder="Pilih tahun akademik"
              disabled={loadingOptions || isGuru}
              onChange={handleYearChange}
            />
  
            <Select
              id="member-class"
              name="class"
              label="Kelas"
              value={classId}
              options={classOptions}
              placeholder="Pilih kelas"
              disabled={loadingOptions || classOptions.length === 0}
              onChange={handleClassChange}
            />
          </div>
  
          {loadingOptions && (
            <LoadingState message="Memuat data kelas dan siswa..." />
          )}
  
          {!loadingOptions && listError && (
            <ErrorState
              message={errorMessage(
                listError,
                'Data anggota kelas tidak dapat dimuat.',
              )}
            />
          )}
  
          {!loadingOptions && !listError && !classId && (
            <EmptyState
              title="Belum ada kelas yang tersedia"
              description="Pilih tahun akademik yang memiliki kelas atau pastikan akun Guru memiliki penugasan Wali Kelas."
            />
          )}
  
          {!loadingOptions && !listError && classId && (
            <>
              <div className="class-members-page__list-heading">
                <div>
                  <h2>{selectedClass?.nama || 'Anggota Kelas'}</h2>
  
                  <p>
                    Tingkat {selectedClass?.tingkat || '-'} ·{' '}
                    {selectedYear ? yearLabel(selectedYear) : '-'}
                  </p>
                </div>
  
                <Badge variant="neutral">
                  {members.length} anggota aktif
                </Badge>
              </div>
  
              {loadingMembers && (
                <LoadingState message="Memuat anggota kelas..." />
              )}
  
              {!loadingMembers &&
                !listError &&
                members.length === 0 && (
                  <EmptyState
                    title="Belum ada anggota kelas"
                    description="Tambahkan siswa untuk mulai mengisi kelas ini."
                  />
                )}
  
              {!loadingMembers &&
                !listError &&
                members.length > 0 && (
                  <Table
                    columns={columns}
                    data={members}
                    rowKey="id"
                    renderCell={(member, column) => {
                      switch (column.key) {
                        case 'identity': {
                          const name = studentName(member.siswa)
                          const username = member.siswa?.user?.username
                          const showUsername =
                            username &&
                            username !== member.siswa?.nama_lengkap &&
                            username !== name
  
                          return (
                            <div className="class-members-page__identity">
                              <strong>{name}</strong>
                              {showUsername && <span>{username}</span>}
                            </div>
                          )
                        }
  
                        case 'nisn':
                          return member.siswa?.nisn || '-'
  
                        case 'nis':
                          return member.siswa?.nis || '-'
  
                        case 'tanggal_mulai':
                          return formatDate(member.tanggal_mulai)
  
                        case 'actions':
                          return (
                            <Button
                              type="button"
                              disabled={endingId !== null || !canManage}
                              loading={
                                String(endingId) ===
                                String(member.siswa_id)
                              }
                              onClick={() =>
                                openEndMembershipModal(member)
                              }
                            >
                              Akhiri Keanggotaan
                            </Button>
                          )
  
                        default:
                          return '-'
                      }
                    }}
                  />
                )}
            </>
          )}
        </Card>
  
        <Modal
          open={modalOpen}
          title="Tambah Anggota Kelas"
          description="Pilih siswa yang akan ditempatkan pada kelas terpilih."
          onClose={() => {
            if (!saving) setModalOpen(false)
          }}
          size="medium"
          footer={
            <>
              <Button
                type="button"
                disabled={saving}
                onClick={() => setModalOpen(false)}
              >
                Batal
              </Button>
  
              <Button
                type="submit"
                form="class-member-form"
                loading={saving}
              >
                Simpan
              </Button>
            </>
          }
        >
          {formError && (
            <div className="class-members-page__form-error">
              <ErrorState
                message={errorMessage(
                  formError,
                  'Penambahan anggota kelas gagal.',
                )}
              />
            </div>
          )}
  
          <form
            id="class-member-form"
            onSubmit={handleAddMember}
          >
            <Select
              id="member-student"
              name="siswa_id"
              label="Siswa"
              value={studentId}
              options={studentOptions}
              placeholder="Pilih siswa"
              required
              disabled={saving}
              onChange={(value) => {
                setStudentId(getSelectValue(value))
              }}
            />
  
            <Input
              id="member-start-date"
              name="tanggal_mulai"
              label="Tanggal Mulai"
              type="date"
              value={startDate}
              required
              disabled={saving}
              onChange={(value) => {
                setStartDate(getSelectValue(value))
              }}
            />
  
            {studentOptions.length === 0 && (
              <p className="class-members-page__hint">
                Tidak ada siswa yang tersedia pada daftar yang dimuat.
                Periksa apakah siswa sudah terdaftar atau sudah menjadi
                anggota kelas.
              </p>
            )}
          </form>
        </Modal>

        <Modal
          open={Boolean(endingTarget)}
          title="Akhiri Keanggotaan?"
          description="Konfirmasi pengakhiran keanggotaan siswa."
          onClose={closeEndMembershipModal}
          size="medium"
          footer={
            <>
              <Button
                type="button"
                disabled={endingId !== null}
                onClick={closeEndMembershipModal}
              >
                Batal
              </Button>
              <Button
                type="button"
                disabled={endingId !== null}
                loading={endingId !== null}
                onClick={handleEndMembership}
                className="class-members-page__end-confirm"
              >
                {endingId !== null ? 'Memproses...' : 'Akhiri Keanggotaan'}
              </Button>
            </>
          }
        >
          {endingTarget && (
            <div className="class-members-page__confirm-content">
              <div className="class-members-page__confirm-icon" aria-hidden="true">
                !
              </div>
              <p>
                Apakah kamu yakin ingin mengakhiri keanggotaan{' '}
                <strong>{studentName(endingTarget.siswa)}</strong> dari kelas{' '}
                <strong>{selectedClass?.nama || '-'}</strong>?
              </p>
              <p className="class-members-page__confirm-warning">
                Riwayat keanggotaan akan tetap disimpan. Siswa tidak lagi
                dianggap sebagai anggota aktif kelas ini.
              </p>
            </div>
          )}
        </Modal>
      </section>
    )
  }
  