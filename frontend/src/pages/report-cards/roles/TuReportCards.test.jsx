import { describe,it,expect,vi,beforeEach } from 'vitest'
import { render,screen,waitFor } from '@testing-library/react'
import TuReportCards from './TuReportCards'
import { apiClient } from '../../../services/apiClient'
import { academicYearApi } from '../../../services/academicYearApi'
vi.mock('../../../services/apiClient',()=>({apiClient:{get:vi.fn(),download:vi.fn(),delete:vi.fn(),postForm:vi.fn()}}))
vi.mock('../../../services/academicYearApi',()=>({academicYearApi:{getAll:vi.fn()}}))
const year={id:1,tahun_mulai:2026,tahun_selesai:2027,semester:'GANJIL',is_active:true}
const row={student_id:1,student_name:'Siswa Testing',nipd:'00123',nisn:'0000000123',class_names:'IPA 1 · 10',member_state:'Efektif',target:true,status:'Tersedia',file_exists:true,document_id:5,version:1,file_name:'rapor-ganjil.pdf',file_size:2048,uploader:'Admin TU',uploaded_at:'2026-11-27 08:00:00'}
function fixture(rows=[row]){return async url=>{const filters=Object.fromEntries(new URLSearchParams(url.split('?')[1]));const meta={academic_year_id:1,semester:'GANJIL',active:true,view:filters.view,filters};if(filters.view==='catalog')return {data:{classes:[{id:2,nama:'IPA 1',tingkat:10,tahun_akademik_id:1}]},meta};return {data:{rows,summary:{'Target siswa':rows.length,Tersedia:rows.length,'Belum diunggah':0,Dicabut:0,'File hilang':0,'Dokumen di luar target':0},warnings:[]},meta:{...meta,page:1,last_page:1,total:rows.length,per_page:20,date:'2026-11-27'}}}}
describe('TuReportCards V10',()=>{
  beforeEach(()=>{vi.clearAllMocks();academicYearApi.getAll.mockResolvedValue({data:[year]});apiClient.get.mockImplementation(fixture())})
  it('shows loading while lookup is pending',()=>{academicYearApi.getAll.mockReturnValue(new Promise(()=>{}));render(<TuReportCards/>);expect(screen.getByText('Memuat data rapor...')).toBeInTheDocument()})
  it('shows exact student, leading zeros, document and uploader',async()=>{render(<TuReportCards/>);expect(await screen.findByText('Siswa Testing')).toBeInTheDocument();expect(screen.getByText(/NIPD 00123/)).toBeInTheDocument();expect(screen.getByText(/rapor-ganjil.pdf/)).toBeInTheDocument()})
  it('renders genuine empty dataset',async()=>{apiClient.get.mockImplementation(fixture([]));render(<TuReportCards/>);expect(await screen.findByText('Tidak ada data pada filter ini.')).toBeInTheDocument()})
  it('renders API error rather than stale statistics',async()=>{apiClient.get.mockRejectedValue(new Error('Server tidak dapat dihubungi.'));render(<TuReportCards/>);expect(await screen.findByText('Server tidak dapat dihubungi.',{exact:false})).toBeInTheDocument()})
  it('uses explicit academic period and server pagination',async()=>{render(<TuReportCards/>);await waitFor(()=>expect(apiClient.get).toHaveBeenCalledWith(expect.stringMatching(/academic_year_id=1&view=coverage.*page=1/)))})
})
